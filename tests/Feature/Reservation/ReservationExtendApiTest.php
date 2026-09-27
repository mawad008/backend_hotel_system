<?php

namespace Tests\Feature\Reservation;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

/**
 * Extend Stay — the dashboard/staff equivalent of
 * Tests\Feature\Guest\GuestReservationExtendTest. Proves the HTTP/auth/policy
 * integration only; pricing/availability rules are already proved there and
 * in ReservationExtensionService's own unit test — not re-proved here.
 */
class ReservationExtendApiTest extends TestCase
{
    private function checkedInReservation(Hotel $hotel, string $basePrice = '300.00'): Reservation
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => $basePrice]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        return Reservation::factory()->withRoom()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_CHECKED_IN,
            'check_in' => now()->subDay()->toDateString(),
            'check_out' => now()->addDay()->toDateString(),
            'price_snapshot' => $basePrice,
        ]);
    }

    public function test_manager_can_extend_a_stay_in_their_assigned_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->checkedInReservation($hotel);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/extend", [
                'new_check_out' => now()->addDays(3)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.extension.nights_added', 2)
            ->assertJsonPath('data.extension.amount', '600.00');

        $this->assertDatabaseHas('reservation_extensions', [
            'reservation_id' => $reservation->id,
            'created_by_staff_id' => $manager->id,
        ]);
    }

    public function test_manager_cannot_extend_a_stay_in_an_unassigned_hotel(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $reservation = $this->checkedInReservation($other);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/extend", [
                'new_check_out' => now()->addDays(3)->toDateString(),
            ])
            ->assertStatus(404);
    }

    public function test_reception_lacking_reservations_manage_is_forbidden(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->checkedInReservation($hotel);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/extend", [
                'new_check_out' => now()->addDays(3)->toDateString(),
            ])
            ->assertStatus(403);
    }

    public function test_extend_on_a_reservation_that_is_not_checked_in_is_a_422(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $hotel = Hotel::factory()->create();
        $reservation = $this->checkedInReservation($hotel);
        $reservation->update(['status' => Reservation::STATUS_VERIFIED]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/extend", [
                'new_check_out' => now()->addDays(3)->toDateString(),
            ])
            ->assertStatus(422);
    }
}
