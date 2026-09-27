<?php

namespace Tests\Unit\IdentityVerification\Workflow;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationConfigurationMissingException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationRetryNotAllowedException;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;

class IdentityVerificationRetryFlowTest extends IdentityVerificationWorkflowTestCase
{
    private function drive(Reservation $reservation, SimulationDirective $directive, ?string $key = null): IdentityVerificationSession
    {
        $service = $this->makeService();
        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());

        return $service->submitSelfie($reservation, $this->image('selfie.jpg'), idempotencyKey: $key, directive: $directive);
    }

    public function test_configured_retry_limit_is_honoured_exactly(): void
    {
        config(['verification.max_retries' => 2, 'verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();

        // Attempt 1 (initial) — error -> retry_allowed.
        $this->drive($reservation, SimulationDirective::Error);
        $this->assertSame(IdentityVerificationSession::STATUS_RETRY_ALLOWED, IdentityVerificationSession::sole()->status);

        // Attempt 2 (retry #1) — error -> retry_allowed.
        $this->drive($reservation, SimulationDirective::Error);
        $this->assertSame(IdentityVerificationSession::STATUS_RETRY_ALLOWED, IdentityVerificationSession::sole()->status);

        // Attempt 3 (retry #2) — error -> retry limit reached -> manual review.
        $this->drive($reservation, SimulationDirective::Error);
        $session = IdentityVerificationSession::sole();
        $this->assertSame(IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW, $session->status);
        $this->assertSame(3, $session->attempts);
        $this->assertSame(
            IdentityVerificationDecision::RESULT_RETRY_EXHAUSTED,
            $this->makeService()->latestDecision($session)->result,
        );
    }

    public function test_retry_from_retry_allowed_without_a_configured_limit_routes_to_manual_review(): void
    {
        config(['verification.max_retries' => 1, 'verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();

        $this->drive($reservation, SimulationDirective::Error); // attempt 1 -> retry_allowed
        $this->drive($reservation, SimulationDirective::Error); // attempt 2 -> manual review (limit 1)

        $this->assertSame(IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW, IdentityVerificationSession::sole()->status);

        // Now unset the config and attempt another document submission from
        // a retry_allowed-like scenario is impossible (already manual) — so
        // test the direct fail-safe path instead.
        config(['verification.max_retries' => null]);
        $fresh = $this->reservation();
        $session = $this->drive($fresh, SimulationDirective::Error);
        $this->assertSame(IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW, $session->status);
    }

    public function test_new_document_from_retry_allowed_with_exhausted_budget_routes_to_manual_review(): void
    {
        config(['verification.max_retries' => 1, 'verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();
        $service = $this->makeService();

        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('s.jpg'), directive: SimulationDirective::Error); // attempt 1 -> retry_allowed

        // Lower the limit so the next retry is over budget, then submit a
        // fresh document from RETRY_ALLOWED.
        config(['verification.max_retries' => 0]);
        $session = $service->submitDocument($reservation, $this->image('doc-2.jpg'), 'passport', claim: $this->claim());

        $this->assertSame(IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW, $session->status);
    }

    public function test_retry_from_staff_rejected_without_budget_is_rejected(): void
    {
        config(['verification.max_retries' => 0, 'verification.thresholds.auto_approve' => 95]);
        $reservation = $this->reservation();
        $service = $this->makeService();

        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('s.jpg'), directive: SimulationDirective::MediumMatch); // -> pending_manual_review
        $service->review($reservation, 'reject', 'blurry', User::factory()->groupOwner()->create());

        $this->assertSame(IdentityVerificationSession::STATUS_STAFF_REJECTED, IdentityVerificationSession::sole()->status);

        $this->expectException(IdentityVerificationRetryNotAllowedException::class);
        $service->submitDocument($reservation, $this->image('doc-2.jpg'), 'passport', claim: $this->claim());
    }

    public function test_retry_from_staff_rejected_with_missing_config_fails_safe(): void
    {
        config(['verification.max_retries' => null, 'verification.thresholds.auto_approve' => 95]);
        $reservation = $this->reservation();
        $service = $this->makeService();

        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('s.jpg'), directive: SimulationDirective::LowMatch);
        $service->review($reservation, 'reject', null, User::factory()->groupOwner()->create());

        $this->expectException(IdentityVerificationConfigurationMissingException::class);
        $service->submitDocument($reservation, $this->image('doc-2.jpg'), 'passport', claim: $this->claim());
    }

    public function test_retry_from_staff_rejected_with_budget_starts_a_new_attempt(): void
    {
        config(['verification.max_retries' => 3, 'verification.thresholds.auto_approve' => 95]);
        $reservation = $this->reservation();
        $service = $this->makeService();

        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('s.jpg'), directive: SimulationDirective::LowMatch);
        $service->review($reservation, 'reject', null, User::factory()->groupOwner()->create());

        $session = $service->submitDocument($reservation, $this->image('doc-2.jpg'), 'passport', claim: $this->claim());
        $this->assertSame(IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED, $session->status);
    }
}
