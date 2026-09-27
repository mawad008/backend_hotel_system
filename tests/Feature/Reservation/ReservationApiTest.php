<?php

namespace Tests\Feature\Reservation;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    private function payload(RoomType $roomType, Guest $guest, array $overrides = []): array
    {
        return array_merge([
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $overrides);
    }

    // ── Authorization: create ──────────────────────────────────────

    public function test_owner_can_create_reservation_in_any_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest))
            ->assertCreated();
    }

    public function test_manager_can_create_in_assigned_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest))
            ->assertCreated();
    }

    public function test_manager_cannot_create_in_unassigned_hotel(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        $guest = Guest::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomTypeB, $guest))
            ->assertStatus(403);
    }

    public function test_reception_cannot_create(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $guest = Guest::factory()->create();
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest))
            ->assertStatus(403);
    }

    public function test_guest_cannot_access_reservation_api(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $guestRecord = Guest::factory()->create();
        $guestUser = User::factory()->guest()->create();

        $this->actingAs($guestUser, 'sanctum')->getJson('/api/v1/reservations')->assertStatus(403);
        $this->actingAs($guestUser, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guestRecord))
            ->assertStatus(403);
    }

    // ── Authorization: list ────────────────────────────────────────

    public function test_owner_list_sees_reservations_across_hotels(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        Reservation::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelA->id])->id]);
        Reservation::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reservations')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_manager_list_is_limited_to_assigned_hotels(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        Reservation::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelA->id])->id]);
        Reservation::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/reservations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_reception_list_is_limited_to_assigned_hotels(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        Reservation::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelA->id])->id]);
        Reservation::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotelA);

        $this->actingAs($reception, 'sanctum')
            ->getJson('/api/v1/reservations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    // ── Authorization: show ────────────────────────────────────────

    public function test_show_accessible_reservation_succeeds(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $reservation->id);
    }

    public function test_show_inaccessible_reservation_returns_404(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => $roomTypeB->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}")
            ->assertStatus(404);
    }

    public function test_show_nonexistent_reservation_returns_404(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reservations/999999')
            ->assertStatus(404);
    }

    // ── Validation / business errors ───────────────────────────────

    public function test_room_type_id_missing_returns_422(): void
    {
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', [
                'guest_id' => $guest->id,
                'check_in' => '2026-11-01',
                'check_out' => '2026-11-05',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['room_type_id']);
    }

    public function test_room_type_id_not_found_returns_422(): void
    {
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', [
                'room_type_id' => 999999,
                'guest_id' => $guest->id,
                'check_in' => '2026-11-01',
                'check_out' => '2026-11-05',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['room_type_id']);
    }

    public function test_guest_id_missing_returns_422(): void
    {
        $roomType = RoomType::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', [
                'room_type_id' => $roomType->id,
                'check_in' => '2026-11-01',
                'check_out' => '2026-11-05',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guest_id']);
    }

    public function test_guest_id_not_found_returns_422(): void
    {
        $roomType = RoomType::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', [
                'room_type_id' => $roomType->id,
                'guest_id' => 999999,
                'check_in' => '2026-11-01',
                'check_out' => '2026-11-05',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guest_id']);
    }

    public function test_room_from_another_hotel_returns_422(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $foreignRoom = Room::factory()->create(['hotel_id' => $otherHotel->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest, ['room_id' => $foreignRoom->id]))
            ->assertStatus(422);
    }

    public function test_room_from_another_room_type_returns_422(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $otherRoomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $mismatchedRoom = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $otherRoomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest, ['room_id' => $mismatchedRoom->id]))
            ->assertStatus(422);
    }

    public function test_check_out_equal_to_check_in_returns_422(): void
    {
        $roomType = RoomType::factory()->create();
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest, [
                'check_in' => '2026-11-01',
                'check_out' => '2026-11-01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['check_out']);
    }

    public function test_check_out_before_check_in_returns_422(): void
    {
        $roomType = RoomType::factory()->create();
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest, [
                'check_in' => '2026-11-05',
                'check_out' => '2026-11-01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['check_out']);
    }

    // ── Server-controlled fields ────────────────────────────────────

    public function test_client_hotel_id_cannot_control_persisted_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest, ['hotel_id' => $otherHotel->id]))
            ->assertCreated();

        $response->assertJsonPath('data.hotel_id', $hotel->id);
    }

    public function test_client_status_and_price_snapshot_cannot_override_server_controlled_values(): void
    {
        $roomType = RoomType::factory()->create(['base_price' => 199.00]);
        Room::factory()->create(['hotel_id' => $roomType->hotel_id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest, [
                'status' => Reservation::STATUS_CHECKED_IN,
                'price_snapshot' => 1.00,
                'created_by_staff_id' => 999999,
                'cancelled_at' => now()->toDateTimeString(),
                'cancellation_reason' => 'not allowed',
            ]))
            ->assertCreated();

        $response->assertJsonPath('data.status', Reservation::STATUS_PENDING)
            ->assertJsonPath('data.price_snapshot', '796.00') // 4 nights × 199.00
            ->assertJsonPath('data.cancelled_at', null)
            ->assertJsonPath('data.cancellation_reason', null);
        $this->assertSame($owner->id, $response->json('data.created_by_staff_id'));
    }

    // ── Side effects ────────────────────────────────────────────────

    public function test_reservation_created_audit_entry_is_created(): void
    {
        $roomType = RoomType::factory()->create();
        Room::factory()->create(['hotel_id' => $roomType->hotel_id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest))
            ->assertCreated();

        $log = AuditLog::where('action', 'reservation.created')->first();
        $this->assertNotNull($log);
        $this->assertSame($response->json('data.id'), $log->auditable_id);
    }

    public function test_room_status_is_not_mutated_by_creating_a_reservation(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guest, ['room_id' => $room->id]))
            ->assertCreated();

        $this->assertSame('available', $room->fresh()->status);
    }

    /**
     * Phase 3D behavior change: this used to prove no availability check
     * existed (both succeeded). Availability protection is now
     * implemented end-to-end through the API — a second overlapping
     * request for the same room must be rejected with 422. See
     * ReservationAvailabilityTest for full Phase 3D coverage.
     */
    public function test_second_overlapping_reservation_for_the_same_room_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guestA, ['room_id' => $room->id]))
            ->assertCreated();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations', $this->payload($roomType, $guestB, ['room_id' => $room->id]))
            ->assertStatus(422);

        $this->assertSame(1, Reservation::where('room_id', $room->id)->count());
    }

    // ── List/response contract ─────────────────────────────────────

    public function test_list_pagination_and_response_envelope(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Reservation::factory()->count(3)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reservations')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_resource_fields_match_the_approved_contract(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'hotel_id', 'room_type_id', 'room_id', 'guest_id',
                'check_in', 'check_out', 'status', 'price_snapshot',
                'created_by_staff_id', 'cancelled_at', 'cancellation_reason',
                'created_at', 'updated_at',
            ]]);
    }

    public function test_validation_error_follows_the_standard_envelope(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/reservations', [])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors'])
            ->assertJson(['success' => false]);
    }
}
