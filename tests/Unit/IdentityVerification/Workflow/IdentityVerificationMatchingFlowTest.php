<?php

namespace Tests\Unit\IdentityVerification\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\Data\VerificationResult;
use App\Domain\IdentityVerification\Provider\MatchOutcome;
use App\Domain\IdentityVerification\Provider\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;

class IdentityVerificationMatchingFlowTest extends IdentityVerificationWorkflowTestCase
{
    private function submitDocument(Reservation $reservation): void
    {
        $this->makeService()->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
    }

    private function submitSelfie(Reservation $reservation, ?SimulationDirective $directive): IdentityVerificationSession
    {
        return $this->makeService()->submitSelfie(
            $reservation,
            $this->image('selfie.jpg'),
            idempotencyKey: null,
            directive: $directive,
        );
    }

    public function test_high_match_above_threshold_auto_approves_and_verifies_the_reservation(): void
    {
        config(['verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();
        $this->submitDocument($reservation);

        $session = $this->submitSelfie($reservation, SimulationDirective::HighMatch);

        $this->assertSame(IdentityVerificationSession::STATUS_AUTO_APPROVED, $session->status);
        $this->assertSame(90, $session->latest_score);
        $this->assertSame(1, $session->attempts);
        $this->assertNotNull($session->decided_at);
        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);

        $this->assertSame(IdentityVerificationAttempt::STATUS_COMPLETED, IdentityVerificationAttempt::sole()->status);
        $decision = IdentityVerificationDecision::sole();
        $this->assertSame(IdentityVerificationDecision::TYPE_AUTOMATED, $decision->type);
        $this->assertSame(IdentityVerificationDecision::RESULT_AUTO_APPROVED, $decision->result);
        $this->assertSame(IdentityVerificationDecision::BAND_HIGH, $decision->band);
    }

    public function test_high_match_below_a_higher_threshold_routes_to_manual_review(): void
    {
        config(['verification.thresholds.auto_approve' => 95]);
        $reservation = $this->reservation();
        $this->submitDocument($reservation);

        $session = $this->submitSelfie($reservation, SimulationDirective::HighMatch);

        $this->assertSame(IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW, $session->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }

    public function test_medium_and_low_match_route_to_manual_review_never_auto_reject(): void
    {
        config(['verification.thresholds.auto_approve' => 80, 'verification.thresholds.manual_review' => 40]);

        foreach ([
            [SimulationDirective::MediumMatch, IdentityVerificationDecision::BAND_MEDIUM],
            [SimulationDirective::LowMatch, IdentityVerificationDecision::BAND_LOW],
        ] as [$directive, $expectedBand]) {
            $reservation = $this->reservation();
            $this->submitDocument($reservation);

            $session = $this->submitSelfie($reservation, $directive);

            $this->assertSame(IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW, $session->status);
            $this->assertSame($expectedBand, $this->makeService()->latestDecision($session)->band);
        }
    }

    public function test_unconfigured_auto_approve_threshold_fails_safe_to_manual_review(): void
    {
        config(['verification.thresholds.auto_approve' => null]);
        $reservation = $this->reservation();
        $this->submitDocument($reservation);

        $session = $this->submitSelfie($reservation, SimulationDirective::HighMatch);

        // No number invented — never auto-approve, never auto-reject.
        $this->assertSame(IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW, $session->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
        $this->assertTrue(
            AuditLog::where('action', 'identity_verification.manual_review_required')->exists(),
        );
    }

    public function test_provider_error_with_retry_budget_allows_a_retry(): void
    {
        config(['verification.max_retries' => 2, 'verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();
        $this->submitDocument($reservation);

        $session = $this->submitSelfie($reservation, SimulationDirective::Error);

        $this->assertSame(IdentityVerificationSession::STATUS_RETRY_ALLOWED, $session->status);
        $this->assertSame(1, $session->attempts);
        $this->assertSame(
            IdentityVerificationDecision::RESULT_RETRY_ALLOWED,
            IdentityVerificationDecision::sole()->result,
        );
    }

    public function test_provider_error_with_no_retry_config_fails_safe_to_manual_review(): void
    {
        config(['verification.max_retries' => null]);
        $reservation = $this->reservation();
        $this->submitDocument($reservation);

        $session = $this->submitSelfie($reservation, SimulationDirective::Error);

        $this->assertSame(IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW, $session->status);
    }

    public function test_late_auto_approval_does_not_touch_a_reservation_that_left_deposit_held(): void
    {
        config(['verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();
        $this->submitDocument($reservation);

        // The reservation is cancelled between document and selfie.
        $reservation->update(['status' => Reservation::STATUS_CANCELLED]);

        $session = $this->submitSelfie($reservation, SimulationDirective::HighMatch);

        $this->assertSame(IdentityVerificationSession::STATUS_AUTO_APPROVED, $session->status);
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
        $this->assertTrue(
            AuditLog::where('action', 'identity_verification.approved_reservation_not_ready')->exists(),
        );
    }

    public function test_duplicate_result_application_is_idempotent(): void
    {
        config(['verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();
        $service = $this->makeService();
        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $session = $service->submitSelfie($reservation, $this->image('selfie.jpg'), idempotencyKey: 'k1', directive: SimulationDirective::HighMatch);

        $attempt = IdentityVerificationAttempt::sole();

        // Re-apply the same result — must be a no-op.
        $again = $service->applyMatchResult(
            $session->id,
            $attempt->id,
            new VerificationResult(
                outcome: MatchOutcome::HighMatch,
                score: 90,
                providerReference: $attempt->provider_reference,
                providerCode: 'dummy_idv_high_match',
                message: 'x',
            ),
            null,
        );

        $this->assertSame(IdentityVerificationSession::STATUS_AUTO_APPROVED, $again->status);
        $this->assertSame(1, $again->attempts);
        $this->assertSame(1, IdentityVerificationDecision::count());
    }
}
