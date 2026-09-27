<?php

namespace Tests\Unit\Models;

use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class IdentityVerificationModelsTest extends TestCase
{
    public function test_session_status_constants_match_the_migration_enum(): void
    {
        $this->assertSame([
            'not_started',
            'document_uploaded',
            'selfie_captured',
            'matching_in_progress',
            'auto_approved',
            'pending_manual_review',
            'staff_approved',
            'staff_rejected',
            'retry_allowed',
        ], IdentityVerificationSession::STATUSES);
    }

    public function test_approved_statuses_are_an_explicit_positive_list(): void
    {
        $this->assertSame(
            ['auto_approved', 'staff_approved'],
            IdentityVerificationSession::APPROVED_STATUSES,
        );
    }

    public function test_session_belongs_to_reservation_guest_and_hotel(): void
    {
        $session = IdentityVerificationSession::factory()->create();

        $this->assertInstanceOf(Reservation::class, $session->reservation);
        $this->assertSame($session->reservation->guest_id, $session->guest_id);
        $this->assertSame($session->reservation->hotel_id, $session->hotel_id);
    }

    public function test_attempt_belongs_to_session_and_casts_metadata(): void
    {
        $attempt = IdentityVerificationAttempt::factory()->create(['metadata' => ['provider_code' => 'x']]);

        $this->assertInstanceOf(IdentityVerificationSession::class, $attempt->session);
        $this->assertSame(['provider_code' => 'x'], $attempt->metadata);
    }

    public function test_decision_is_append_only(): void
    {
        $this->assertNull(IdentityVerificationDecision::UPDATED_AT);

        $decision = IdentityVerificationDecision::factory()->create();
        $this->assertArrayNotHasKey('updated_at', $decision->getAttributes());
    }
}
