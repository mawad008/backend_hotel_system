<?php

namespace Tests\Feature\Guest;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class GuestCheckInTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function verifiedReservation(Guest $guest): Reservation
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->withRoom()->create([
            'guest_id' => $guest->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_VERIFIED,
            'check_out' => now()->addDays(3)->format('Y-m-d'),
        ]);
        Payment::factory()->holdActive()->create(['reservation_id' => $reservation->id, 'hotel_id' => $hotel->id]);
        IdentityVerificationSession::factory()->autoApproved()->create(['reservation_id' => $reservation->id]);

        return $reservation;
    }

    public function test_unauthenticated_is_401(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_VERIFIED]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")->assertStatus(401);
        $this->assertDatabaseCount('access_grants', 0);
    }

    public function test_eligible_guest_checks_in(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->verifiedReservation($guest);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")
            ->assertStatus(201)
            ->assertJsonPath('data.status', AccessGrant::STATUS_ACTIVE);

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => Reservation::STATUS_IN_STAY]);
    }

    public function test_a_reservation_without_payment_hold_or_identity_cannot_check_in(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create([
            'guest_id' => $guest->id, 'status' => Reservation::STATUS_VERIFIED,
        ]);

        // Client cannot skip the same eligibility check the staff surface enforces.
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")->assertStatus(422);
    }

    public function test_guest_cannot_check_in_another_guests_reservation(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create(['status' => Reservation::STATUS_VERIFIED]);

        $this->postJson("/api/v1/guest/reservations/{$other->id}/check-in")->assertStatus(404);
        $this->assertDatabaseCount('access_grants', 0);
    }
}
