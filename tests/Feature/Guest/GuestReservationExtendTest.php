<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use Tests\TestCase;

class GuestReservationExtendTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    /**
     * A checked-in reservation with a physically assigned room, ready to be
     * extended. $basePrice is the room type's nightly rate.
     */
    private function checkedInReservation(Guest $guest, string $basePrice = '300.00', int $roomCount = 1): Reservation
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => $basePrice]);
        Room::factory()->count($roomCount)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        return Reservation::factory()->withRoom()->create([
            'guest_id' => $guest->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_CHECKED_IN,
            'check_in' => now()->subDay()->toDateString(),
            'check_out' => now()->addDay()->toDateString(),
            'price_snapshot' => $basePrice,
        ]);
    }

    public function test_guest_extends_a_checked_in_reservation(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->checkedInReservation($guest, basePrice: '300.00');
        $newCheckOut = now()->addDays(3)->toDateString();

        $res = $this->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", [
            'new_check_out' => $newCheckOut,
        ]);

        $res->assertOk()
            ->assertJsonPath('data.reservation.id', $reservation->id)
            ->assertJsonPath('data.reservation.check_out', $newCheckOut)
            ->assertJsonPath('data.extension.nights_added', 2)
            ->assertJsonPath('data.extension.amount', '600.00')
            ->assertJsonPath('data.extension.folio_posted', true)
            ->assertJsonPath('data.folio.totals.charges_total', '600.00');

        // price_snapshot is the reservation's pre-extension snapshot (300.00,
        // set by the factory) plus the incremental amount (600.00) — the
        // extension never replaces the original snapshot, it adds to it.
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'check_out' => $newCheckOut,
            'price_snapshot' => '900.00',
        ]);

        $this->assertDatabaseHas('reservation_extensions', [
            'reservation_id' => $reservation->id,
            'nights_added' => 2,
            'unit_price' => '300.00',
            'amount' => '600.00',
        ]);

        $this->assertDatabaseHas('folio_charges', [
            'reservation_id' => $reservation->id,
            'source_type' => FolioCharge::SOURCE_STAY_EXTENSION,
            'total_amount' => '600.00',
            'status' => FolioCharge::STATUS_POSTED,
        ]);
    }

    public function test_guest_cannot_extend_a_reservation_that_is_not_checked_in(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->checkedInReservation($guest);
        $reservation->update(['status' => Reservation::STATUS_VERIFIED]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", [
            'new_check_out' => now()->addDays(3)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_extend_rejects_a_new_check_out_that_is_not_after_the_current_one(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->checkedInReservation($guest);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", [
            'new_check_out' => $reservation->check_out->toDateString(),
        ])->assertStatus(422);
    }

    public function test_extend_is_rejected_when_the_added_nights_are_not_available(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->checkedInReservation($guest, roomCount: 1);

        // The next guest already has this exact room from the moment this
        // guest's current stay ends.
        Reservation::factory()->create([
            'hotel_id' => $reservation->hotel_id,
            'room_type_id' => $reservation->room_type_id,
            'room_id' => $reservation->room_id,
            'status' => Reservation::STATUS_VERIFIED,
            'check_in' => $reservation->check_out->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
        ]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", [
            'new_check_out' => now()->addDays(3)->toDateString(),
        ])->assertStatus(422);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'check_out' => $reservation->check_out->toDateString(),
        ]);
    }

    public function test_extend_replays_idempotently_with_the_same_key(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->checkedInReservation($guest, basePrice: '300.00');
        $newCheckOut = now()->addDays(3)->toDateString();

        $first = $this->withHeader('Idempotency-Key', 'ext-key-1')
            ->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", ['new_check_out' => $newCheckOut])
            ->assertOk();

        $second = $this->withHeader('Idempotency-Key', 'ext-key-1')
            ->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", ['new_check_out' => $newCheckOut])
            ->assertOk();

        $this->assertSame($first->json('data.extension.id'), $second->json('data.extension.id'));
        $this->assertDatabaseCount('reservation_extensions', 1);
        $this->assertDatabaseCount('folio_charges', 1);
    }

    public function test_extend_idempotency_key_reused_for_a_different_date_is_a_conflict(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->checkedInReservation($guest);

        $this->withHeader('Idempotency-Key', 'ext-key-2')
            ->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", [
                'new_check_out' => now()->addDays(3)->toDateString(),
            ])->assertOk();

        $this->withHeader('Idempotency-Key', 'ext-key-2')
            ->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", [
                'new_check_out' => now()->addDays(4)->toDateString(),
            ])->assertStatus(422);
    }

    public function test_extend_preserves_the_original_payment_amount(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->checkedInReservation($guest, basePrice: '300.00');
        Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'amount' => '300.00',
        ]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", [
            'new_check_out' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'reservation_id' => $reservation->id,
            'amount' => '300.00',
        ]);
    }

    public function test_guest_cannot_extend_another_guests_reservation(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create(['status' => Reservation::STATUS_CHECKED_IN]);

        $this->postJson("/api/v1/guest/reservations/{$other->id}/extend", [
            'new_check_out' => now()->addDays(3)->toDateString(),
        ])->assertStatus(404);
    }

    public function test_the_extend_endpoint_requires_a_guest_token(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_CHECKED_IN]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", [
            'new_check_out' => now()->addDays(3)->toDateString(),
        ])->assertStatus(401);
    }
}
