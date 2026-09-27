<?php

namespace Tests\Feature\IdentityVerification;

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

class IdentityVerificationIntegrationTest extends TestCase
{
    use UsesIdentitySpecimen;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['verification.thresholds.auto_approve' => 80, 'verification.max_retries' => 2]);
    }

    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function depositHeldReservation(): Reservation
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ]);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('x.jpg', 12, 12);
    }

    public function test_full_auto_approval_journey_advances_the_reservation(): void
    {
        $owner = $this->owner();
        $reservation = $this->depositHeldReservation();

        $this->actingAs($owner, 'sanctum')
            ->post("/api/v1/identity-verification/{$reservation->id}/documents", ['document' => $this->image()] + $this->specimenClaimFields())
            ->assertStatus(201);

        $this->actingAs($owner, 'sanctum')
            ->withHeaders(['X-Identity-Simulate' => 'high_match'])
            ->post("/api/v1/identity-verification/{$reservation->id}/selfie", ['selfie' => $this->image()])
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED);

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
    }

    public function test_manual_review_journey_advances_the_reservation(): void
    {
        $owner = $this->owner();
        $reservation = $this->depositHeldReservation();

        $this->actingAs($owner, 'sanctum')
            ->post("/api/v1/identity-verification/{$reservation->id}/documents", ['document' => $this->image()] + $this->specimenClaimFields())
            ->assertStatus(201);
        $this->actingAs($owner, 'sanctum')
            ->withHeaders(['X-Identity-Simulate' => 'medium_match'])
            ->post("/api/v1/identity-verification/{$reservation->id}/selfie", ['selfie' => $this->image()])
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW);

        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/identity-verification/{$reservation->id}/review", ['decision' => 'approve'])
            ->assertOk();

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
    }

    public function test_identity_verification_does_not_alter_the_reservation_state_machine(): void
    {
        // The Phase 4A transition table is untouched by Phase 6.
        $this->assertSame([
            Reservation::STATUS_VERIFIED,
            Reservation::STATUS_CANCELLED,
        ], ReservationStateMachine::allowedTransitions(Reservation::STATUS_DEPOSIT_HELD));
    }

    public function test_existing_payment_hold_flow_is_unaffected(): void
    {
        config(['payment.providers.dummy.default_directive' => 'success']);
        $owner = $this->owner();

        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_PENDING,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/payment/hold", ['amount' => '100.00'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', Payment::STATUS_HOLD_ACTIVE);

        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }
}
