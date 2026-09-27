<?php

namespace Tests\Feature\Guest;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class GuestCheckoutTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function reservationFor(Guest $guest, string $status = Reservation::STATUS_IN_STAY): Reservation
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => $status, 'price_snapshot' => '0.00',
        ]);
        Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'amount' => '0.00', 'currency' => 'USD',
        ]);

        return $reservation;
    }

    public function test_unauthenticated_is_401(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_IN_STAY]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/checkout")->assertStatus(401);
    }

    public function test_guest_checks_out_their_own_reservation(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/checkout")
            ->assertOk()
            ->assertJsonPath('data.checkout.status', Checkout::STATUS_COMPLETED);
    }

    public function test_an_amount_supplied_by_the_client_is_ignored(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/checkout", ['amount' => '999999.00'])
            ->assertOk()
            ->assertJsonPath('data.totals.outstanding_total', '0.00');
    }

    public function test_guest_cannot_checkout_another_guests_reservation(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create(['status' => Reservation::STATUS_IN_STAY]);

        $this->postJson("/api/v1/guest/reservations/{$other->id}/checkout")->assertStatus(404);
    }
}
