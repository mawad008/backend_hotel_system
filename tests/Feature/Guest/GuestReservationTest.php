<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class GuestReservationTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function bookableRoomType(int $rooms = 3): RoomType
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => 500, 'capacity' => 3]);
        Room::factory()->count($rooms)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        return $roomType;
    }

    private function stay(): array
    {
        return [
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'adults' => 2,
            'children' => 0,
        ];
    }

    public function test_guest_creates_a_pending_reservation_for_themselves(): void
    {
        $guest = $this->actingGuest();
        $roomType = $this->bookableRoomType();

        $res = $this->postJson('/api/v1/guest/reservations', [
            'room_type_id' => $roomType->id,
            ...$this->stay(),
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.status', Reservation::STATUS_PENDING)
            ->assertJsonPath('data.hotel_id', $roomType->hotel_id)
            ->assertJsonPath('data.room_type_id', $roomType->id)
            ->assertJsonPath('data.nights', 3)
            ->assertJsonPath('data.adults', 2)
            ->assertJsonPath('data.price_snapshot', '1500.00') // 3 nights × 500.00
            // guest-safe shape — no staff-only fields leak
            ->assertJsonMissingPath('data.created_by_staff_id')
            ->assertJsonMissingPath('data.guest_id')
            ->assertJsonMissingPath('data.room_id');

        $this->assertDatabaseHas('reservations', [
            'id' => $res->json('data.id'),
            'guest_id' => $guest->id,
            'created_by_staff_id' => null,
            'adults' => 2,
        ]);
    }

    public function test_guest_only_lists_and_reads_their_own_reservations(): void
    {
        $mine = $this->actingGuest();
        $roomType = $this->bookableRoomType();
        $ownReservation = Reservation::factory()->create([
            'guest_id' => $mine->id,
            'hotel_id' => $roomType->hotel_id,
            'room_type_id' => $roomType->id,
        ]);
        $otherReservation = Reservation::factory()->create();

        $this->getJson('/api/v1/guest/reservations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownReservation->id);

        $this->getJson("/api/v1/guest/reservations/{$ownReservation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownReservation->id);

        // Someone else's reservation is an identical plain 404 — never 403.
        $this->getJson("/api/v1/guest/reservations/{$otherReservation->id}")->assertStatus(404);
        $this->getJson('/api/v1/guest/reservations/999999')->assertStatus(404);
    }

    public function test_the_booking_surface_requires_a_guest_token(): void
    {
        $this->getJson('/api/v1/guest/reservations')->assertStatus(401);
        $this->postJson('/api/v1/guest/reservations', [])->assertStatus(401);
    }

    public function test_a_staff_token_cannot_use_the_guest_booking_surface(): void
    {
        $staff = User::factory()->groupOwner()->create();
        $this->withToken($staff->createToken('api')->plainTextToken)
            ->getJson('/api/v1/guest/reservations')
            ->assertStatus(401);
    }

    public function test_booking_rejects_an_inactive_room_type_or_hotel(): void
    {
        $this->actingGuest();

        $inactiveType = RoomType::factory()->inactive()->create();
        $this->postJson('/api/v1/guest/reservations', ['room_type_id' => $inactiveType->id, ...$this->stay()])
            ->assertStatus(422)->assertJsonValidationErrors('room_type_id');

        $inactiveHotel = Hotel::factory()->create(['is_active' => false]);
        $typeOfInactiveHotel = RoomType::factory()->create(['hotel_id' => $inactiveHotel->id]);
        $this->postJson('/api/v1/guest/reservations', ['room_type_id' => $typeOfInactiveHotel->id, ...$this->stay()])
            ->assertStatus(422)->assertJsonValidationErrors('room_type_id');
    }

    public function test_booking_rejects_a_party_that_exceeds_capacity(): void
    {
        $this->actingGuest();
        $roomType = $this->bookableRoomType();

        $this->postJson('/api/v1/guest/reservations', [
            'room_type_id' => $roomType->id,
            ...[...$this->stay(), 'adults' => 3, 'children' => 2],
        ])->assertStatus(422)->assertJsonValidationErrors('adults');
    }

    public function test_booking_surfaces_a_sold_out_stay_as_422(): void
    {
        $this->actingGuest();
        $roomType = $this->bookableRoomType(rooms: 1);

        Reservation::factory()->create([
            'hotel_id' => $roomType->hotel_id,
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ]);

        $this->postJson('/api/v1/guest/reservations', ['room_type_id' => $roomType->id, ...$this->stay()])
            ->assertStatus(422);
    }

    public function test_guest_cancels_a_pending_reservation(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create([
            'guest_id' => $guest->id,
            'status' => Reservation::STATUS_PENDING,
        ]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CANCELLED);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CANCELLED,
        ]);
    }

    public function test_guest_cannot_cancel_a_checked_in_reservation(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create([
            'guest_id' => $guest->id,
            'status' => Reservation::STATUS_CHECKED_IN,
        ]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/cancel")->assertStatus(422);
    }

    public function test_guest_cannot_cancel_another_guests_reservation(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $this->postJson("/api/v1/guest/reservations/{$other->id}/cancel")->assertStatus(404);
    }
}
