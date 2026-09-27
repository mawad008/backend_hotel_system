<?php

namespace Tests\Feature\DigitalAccess;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\StateMachine\DigitalAccessStateMachine;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\StateMachine\ReservationStateMachine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesIdentitySpecimen;
use Tests\TestCase;

/**
 * Phase 7 — the end-to-end guest journey from DEPOSIT_HELD through the real
 * Payment (hold) and Identity Verification APIs to CHECKED_IN, and proof
 * that Phase 7 leaves the earlier domains untouched.
 */
class DigitalAccessIntegrationTest extends TestCase
{
    use UsesIdentitySpecimen;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'verification.thresholds.auto_approve' => 80,
            'verification.max_retries' => 2,
        ]);
    }

    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    public function test_full_journey_deposit_held_to_checked_in(): void
    {
        $owner = $this->owner();
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->withRoom()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_PENDING,
            'check_out' => now()->addDays(3)->format('Y-m-d'),
        ]);

        // 1. Payment hold -> DEPOSIT_HELD
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/payment/hold", ['amount' => '150.00'])
            ->assertStatus(201);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);

        // 2. Identity verification -> VERIFIED
        $this->actingAs($owner, 'sanctum')
            ->post("/api/v1/identity-verification/{$reservation->id}/documents", [
                'document' => UploadedFile::fake()->image('id.jpg'),
            ] + $this->specimenClaimFields())->assertStatus(201);
        $this->actingAs($owner, 'sanctum')
            ->withHeaders(['X-Identity-Simulate' => 'high_match'])
            ->post("/api/v1/identity-verification/{$reservation->id}/selfie", [
                'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            ])->assertOk();
        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);

        // 3. Check-in -> CHECKED_IN + active digital access
        $response = $this->actingAs($owner, 'sanctum')
            ->withHeaders(['X-Digital-Access-Simulate' => 'success'])
            ->postJson("/api/v1/check-in/{$reservation->id}")
            ->assertStatus(201)
            ->assertJsonPath('data.status', AccessGrant::STATUS_ACTIVE);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('data.credential'));
        // Approved 2026-09-26: the stay starts at check-in.
        $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);

        // 4. Access status still reflects the active grant
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/access/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.status', AccessGrant::STATUS_ACTIVE);
    }

    public function test_phase_7_does_not_change_the_reservation_state_machine(): void
    {
        // Phase 4A transition table, VERIFIED row, is untouched.
        $this->assertSame([
            Reservation::STATUS_CHECKED_IN,
            Reservation::STATUS_CANCELLED,
        ], ReservationStateMachine::allowedTransitions(Reservation::STATUS_VERIFIED));

        // CHECKED_IN -> IN_STAY still exists; check-in drives it (the stay
        // starts at check-in, approved 2026-09-26).
        $this->assertSame(
            [Reservation::STATUS_IN_STAY],
            ReservationStateMachine::allowedTransitions(Reservation::STATUS_CHECKED_IN),
        );
    }

    public function test_check_in_does_not_capture_the_payment_or_change_identity_state(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->withRoom()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_VERIFIED,
            'check_out' => now()->addDays(3)->format('Y-m-d'),
        ]);
        $payment = Payment::factory()->holdActive()->create(['reservation_id' => $reservation->id, 'hotel_id' => $hotel->id]);
        $idv = IdentityVerificationSession::factory()
            ->autoApproved()->create(['reservation_id' => $reservation->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/check-in/{$reservation->id}")
            ->assertStatus(201);

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->fresh()->status);
        $this->assertSame($idv->status, $idv->fresh()->status);
    }

    public function test_state_machine_business_lifecycle_matches_baseline_section_11(): void
    {
        // §11: NOT_ISSUED -> ISSUED(active) -> {EXPIRED | REVOKED}. The
        // *_REQUESTED / FAILED nodes are architecture-only and are never
        // terminal.
        $this->assertTrue(DigitalAccessStateMachine::isTerminal(AccessGrant::STATUS_EXPIRED));
        $this->assertTrue(DigitalAccessStateMachine::isTerminal(AccessGrant::STATUS_REVOKED));
        $this->assertFalse(DigitalAccessStateMachine::isTerminal(AccessGrant::STATUS_FAILED));
        $this->assertContains(AccessGrant::STATUS_EXPIRED, DigitalAccessStateMachine::allowedTransitions(AccessGrant::STATUS_ACTIVE));
        $this->assertContains(AccessGrant::STATUS_REVOKE_REQUESTED, DigitalAccessStateMachine::allowedTransitions(AccessGrant::STATUS_ACTIVE));
    }
}
