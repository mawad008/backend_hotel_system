<?php

namespace Tests\Feature\Room;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoomApiTest extends TestCase
{
    // ── Authorization ──────────────────────────────────────────────

    public function test_group_owner_can_view_rooms_across_multiple_hotels(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotelA->id]);
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        Room::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => $roomTypeA->id]);
        Room::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => $roomTypeB->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/hotels/{$hotelA->id}/rooms")->assertOk();
        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/hotels/{$hotelB->id}/rooms")->assertOk();
    }

    public function test_assigned_hotel_manager_can_fully_access_their_hotels_rooms(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/rooms")->assertOk();
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}")->assertOk();
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $roomType->id, 'room_number' => '202',
        ])->assertCreated();
    }

    public function test_unassigned_hotel_manager_cannot_access_another_hotels_rooms(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        $roomB = Room::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => $roomTypeB->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/hotels/{$hotelB->id}/rooms")->assertStatus(403);
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/hotels/{$hotelB->id}/rooms/{$roomB->id}")->assertStatus(403);
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/hotels/{$hotelB->id}/rooms", [
            'room_type_id' => $roomTypeB->id, 'room_number' => '303',
        ])->assertStatus(403);
    }

    public function test_reception_can_view_assigned_hotels_rooms(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/rooms")->assertOk();
        $this->actingAs($reception, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}")->assertOk();
    }

    public function test_reception_cannot_create_or_update_rooms(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $roomType->id, 'room_number' => '404',
        ])->assertStatus(403);

        $this->actingAs($reception, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}", ['room_number' => '405'])
            ->assertStatus(403);
    }

    public function test_reception_cannot_change_room_status(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}/status", ['status' => 'under_maintenance'])
            ->assertStatus(403);
    }

    public function test_guest_cannot_access_room_endpoints(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = User::factory()->guest()->create();

        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/rooms")->assertStatus(403);
        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}")->assertStatus(403);
    }

    // ── Room CRUD / listing ─────────────────────────────────────────

    public function test_list_rooms_is_paginated(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->count(3)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/rooms")
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_rooms_can_be_filtered_by_room_type_id(): void
    {
        $hotel = Hotel::factory()->create();
        $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Type A']);
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Type B']);
        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomTypeA->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomTypeB->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/rooms?room_type_id={$roomTypeA->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_show_returns_the_requested_room(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $room->id)
            ->assertJsonPath('data.room_number', $room->room_number);
    }

    public function test_create_room_persists_and_audits(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $roomType->id,
            'room_number' => '501',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Created successfully.')
            ->assertJsonPath('data.room_number', '501')
            ->assertJsonPath('data.hotel_id', $hotel->id);

        $this->assertDatabaseHas('rooms', ['hotel_id' => $hotel->id, 'room_number' => '501']);
        $this->assertNotNull(AuditLog::where('action', 'room.created')->first());
    }

    public function test_create_always_starts_available_even_if_client_sends_a_status(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $roomType->id,
            'room_number' => '601',
            'status' => 'booked',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'available');
        $this->assertDatabaseHas('rooms', ['room_number' => '601', 'status' => 'available']);
    }

    public function test_client_cannot_force_hotel_id_on_create(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/rooms", [
            'hotel_id' => $otherHotel->id,
            'room_type_id' => $roomType->id,
            'room_number' => '701',
        ]);

        $response->assertCreated()->assertJsonPath('data.hotel_id', $hotel->id);
    }

    public function test_cross_hotel_room_type_id_is_rejected_on_create(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $foreignRoomType = RoomType::factory()->create(['hotel_id' => $otherHotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $foreignRoomType->id,
            'room_number' => '801',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertDatabaseMissing('rooms', ['room_number' => '801']);
    }

    public function test_update_room(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_number' => '901']);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}", ['room_number' => '902'])
            ->assertOk()
            ->assertJsonPath('data.room_number', '902');

        $this->assertNotNull(AuditLog::where('action', 'room.updated')->first());
    }

    public function test_update_cannot_change_status_or_hotel_id(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->putJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}", [
            'hotel_id' => $otherHotel->id,
            'status' => 'booked',
            'room_number' => '1001',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.hotel_id', $hotel->id)
            ->assertJsonPath('data.status', 'available');
    }

    public function test_cross_hotel_room_type_id_is_rejected_on_update(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $foreignRoomType = RoomType::factory()->create(['hotel_id' => $otherHotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}", ['room_type_id' => $foreignRoomType->id])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_duplicate_room_number_within_the_same_hotel_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_number' => '101']);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $roomType->id,
            'room_number' => '101',
        ])->assertStatus(422)->assertJsonValidationErrors(['room_number']);
    }

    public function test_a_room_in_hotel_a_cannot_be_accessed_through_hotel_bs_route(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotelA->id]);
        $roomA = Room::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => $roomTypeA->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/rooms/{$roomA->id}")
            ->assertStatus(404);
    }

    // ── Status transitions ──────────────────────────────────────────

    public function test_available_to_under_maintenance_transition_succeeds(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}/status", ['status' => 'under_maintenance'])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_maintenance');

        $this->assertNotNull(AuditLog::where('action', 'room.status_updated')->first());
    }

    public function test_under_maintenance_to_available_transition_succeeds(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->underMaintenance()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}/status", ['status' => 'available'])
            ->assertOk()
            ->assertJsonPath('data.status', 'available');
    }

    public static function invalidStatusTargets(): array
    {
        return [
            'available -> booked' => ['available', 'booked'],
            'under_maintenance -> booked' => ['under_maintenance', 'booked'],
        ];
    }

    #[DataProvider('invalidStatusTargets')]
    public function test_transitions_to_booked_are_rejected_by_validation(string $from, string $to): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => $from]);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}/status", ['status' => $to]);

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame($from, $room->fresh()->status);
    }

    public static function transitionsFromBooked(): array
    {
        return [
            'booked -> available' => ['available'],
            'booked -> under_maintenance' => ['under_maintenance'],
        ];
    }

    #[DataProvider('transitionsFromBooked')]
    public function test_transitions_from_booked_are_rejected_by_the_service(string $to): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->booked()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}/status", ['status' => $to]);

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame('booked', $room->fresh()->status);
    }

    public function test_same_state_transition_is_rejected_matching_phase_2b_behavior(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/rooms/{$room->id}/status", ['status' => 'available'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ── Error format ────────────────────────────────────────────────

    public function test_validation_error_follows_the_standard_envelope(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/rooms", [])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors'])
            ->assertJson(['success' => false]);
    }

    public function test_business_exception_is_mapped_to_the_standard_error_envelope(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $foreignRoomType = RoomType::factory()->create(['hotel_id' => $otherHotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $foreignRoomType->id,
            'room_number' => '1101',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['success', 'message'])->assertJson(['success' => false]);
    }
}
