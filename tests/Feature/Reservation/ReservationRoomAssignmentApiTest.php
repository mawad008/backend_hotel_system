<?php

namespace Tests\Feature\Reservation;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

/**
 * Front-desk room assignment for a room-less (guest-app) reservation:
 * POST /api/v1/reservations/{reservation}/room.
 */
class ReservationRoomAssignmentApiTest extends TestCase
{
    /** @return array{Hotel, RoomType, Reservation} */
    private function roomlessReservation(string $status = Reservation::STATUS_VERIFIED): array
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'room_id' => null,
            'status' => $status,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
        ]);

        return [$hotel, $roomType, $reservation];
    }

    /** Reception holds the dedicated `reservations.assign-room` permission. */
    private function manager(Hotel $hotel): User
    {
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        return $reception;
    }

    public function test_manager_assigns_a_free_room_of_the_booked_type(): void
    {
        [$hotel, $roomType, $reservation] = $this->roomlessReservation();
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        $this->actingAs($this->manager($hotel), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $room->id])
            ->assertOk()
            ->assertJsonPath('data.room_id', $room->id);

        $this->assertSame($room->id, $reservation->fresh()->room_id);
        $this->assertNotNull($reservation->fresh()->room_assigned_at);
        $this->assertNotNull($reservation->fresh()->room_assigned_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.room_assigned']);
    }

    public function test_reassigning_the_same_room_does_not_conflict_with_itself(): void
    {
        [$hotel, $roomType, $reservation] = $this->roomlessReservation();
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $reservation->update(['room_id' => $room->id]);

        $this->actingAs($this->manager($hotel), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $room->id])
            ->assertOk();
    }

    public function test_a_room_booked_for_overlapping_dates_is_refused(): void
    {
        [$hotel, $roomType, $reservation] = $this->roomlessReservation();
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'room_id' => $room->id,
            'status' => Reservation::STATUS_DEPOSIT_HELD,
            'check_in' => now()->addDays(2)->toDateString(),
            'check_out' => now()->addDays(4)->toDateString(),
        ]);

        $this->actingAs($this->manager($hotel), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $room->id])
            ->assertStatus(422);

        $this->assertNull($reservation->fresh()->room_id);
    }

    public function test_a_room_of_another_type_or_hotel_is_refused(): void
    {
        [$hotel, , $reservation] = $this->roomlessReservation();
        $otherType = Room::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotel->id])->id,
        ]);
        $otherHotel = Room::factory()->create();

        $manager = $this->manager($hotel);
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $otherType->id])
            ->assertStatus(422);
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $otherHotel->id])
            ->assertStatus(422);
    }

    public function test_a_cancelled_or_checked_out_reservation_cannot_get_a_room(): void
    {
        foreach ([Reservation::STATUS_CANCELLED, Reservation::STATUS_CHECKED_OUT] as $status) {
            [$hotel, $roomType, $reservation] = $this->roomlessReservation($status);
            $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

            $this->actingAs($this->manager($hotel), 'sanctum')
                ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $room->id])
                ->assertStatus(422);
        }
    }

    public function test_reservations_manage_alone_and_out_of_scope_staff_are_denied(): void
    {
        [$hotel, $roomType, $reservation] = $this->roomlessReservation();
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        // A hotel manager holds reservations.manage but not assign-room.
        $hotelManager = User::factory()->hotelManager()->create();
        $hotelManager->hotels()->attach($hotel);
        $this->actingAs($hotelManager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $room->id])
            ->assertStatus(403);

        $outsider = $this->manager(Hotel::factory()->create());
        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $room->id])
            ->assertStatus(404);
    }

    public function test_assignable_rooms_exclude_booked_and_maintenance_rooms(): void
    {
        [$hotel, $roomType, $reservation] = $this->roomlessReservation();
        $free = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_number' => '101']);
        $booked = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_number' => '102']);
        $maintenance = Room::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_number' => '103', 'status' => 'under_maintenance',
        ]);
        Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'room_id' => $booked->id,
            'status' => Reservation::STATUS_VERIFIED,
            'check_in' => $reservation->check_in->toDateString(),
            'check_out' => $reservation->check_out->toDateString(),
        ]);

        $manager = $this->manager($hotel);
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/assignable-rooms")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $free->id);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $maintenance->id])
            ->assertStatus(422);
    }
}
