<?php

namespace Tests\Unit\IdentityVerification\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationActionNotAllowedException;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;

class IdentityVerificationReviewFlowTest extends IdentityVerificationWorkflowTestCase
{
    private function pendingReview(Reservation $reservation): void
    {
        config(['verification.thresholds.auto_approve' => 95]);
        $service = $this->makeService();
        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('selfie.jpg'), directive: SimulationDirective::MediumMatch);
    }

    public function test_staff_approval_verifies_the_reservation(): void
    {
        $reservation = $this->reservation();
        $this->pendingReview($reservation);
        $actor = User::factory()->groupOwner()->create();

        $session = $this->makeService()->review($reservation, 'approve', 'looks fine', $actor);

        $this->assertSame(IdentityVerificationSession::STATUS_STAFF_APPROVED, $session->status);
        $this->assertNotNull($session->decided_at);
        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);

        $decision = $this->makeService()->latestDecision($session);
        $this->assertSame(IdentityVerificationDecision::TYPE_MANUAL, $decision->type);
        $this->assertSame(IdentityVerificationDecision::RESULT_STAFF_APPROVED, $decision->result);
        $this->assertSame($actor->id, $decision->decided_by_user_id);
        $this->assertSame('looks fine', $decision->reason);
        $this->assertTrue(AuditLog::where('action', 'identity_verification.staff_approved')->exists());
    }

    public function test_staff_rejection_leaves_the_reservation_in_deposit_held(): void
    {
        $reservation = $this->reservation();
        $this->pendingReview($reservation);

        $session = $this->makeService()->review($reservation, 'reject', 'mismatch', User::factory()->hotelManager()->create());

        $this->assertSame(IdentityVerificationSession::STATUS_STAFF_REJECTED, $session->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }

    public function test_review_is_rejected_when_the_session_is_not_pending_manual_review(): void
    {
        config(['verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();
        $service = $this->makeService();
        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('selfie.jpg'), directive: SimulationDirective::HighMatch);

        $this->expectException(IdentityVerificationActionNotAllowedException::class);
        $service->review($reservation, 'approve', null, User::factory()->groupOwner()->create());
    }

    public function test_review_on_a_reservation_with_no_session_is_rejected(): void
    {
        $this->expectException(IdentityVerificationActionNotAllowedException::class);

        $this->makeService()->review(
            $this->reservation(),
            'approve',
            null,
            User::factory()->groupOwner()->create(),
        );
    }
}
