<?php

namespace Tests\Feature\RoomType;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use Tests\TestCase;

class RoomTypeApiTest extends TestCase
{
    // ── Authorization ──────────────────────────────────────────────

    public function test_group_owner_can_view_room_types_across_multiple_hotels(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        RoomType::factory()->create(['hotel_id' => $hotelA->id]);
        RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/hotels/{$hotelA->id}/room-types")->assertOk();
        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/hotels/{$hotelB->id}/room-types")->assertOk();
    }

    public function test_group_owner_can_create_and_update_room_types(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $create = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/room-types", [
            'name' => 'Deluxe Double',
            'base_price' => 150,
            'capacity' => 2,
        ])->assertCreated();

        $id = $create->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_assigned_hotel_manager_can_fully_access_their_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/room-types")->assertOk();
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}")->assertOk();
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/room-types", [
            'name' => 'Suite', 'base_price' => 300, 'capacity' => 4,
        ])->assertCreated();
        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}", ['capacity' => 3])
            ->assertOk();
    }

    public function test_unassigned_hotel_manager_cannot_access_another_hotel(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/hotels/{$hotelB->id}/room-types")->assertStatus(403);
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/hotels/{$hotelB->id}/room-types/{$roomTypeB->id}")->assertStatus(403);
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/hotels/{$hotelB->id}/room-types", [
            'name' => 'X', 'base_price' => 10, 'capacity' => 1,
        ])->assertStatus(403);
        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotelB->id}/room-types/{$roomTypeB->id}", ['name' => 'Y'])
            ->assertStatus(403);
    }

    public function test_reception_can_view_assigned_hotel_inventory(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/room-types")->assertOk();
        $this->actingAs($reception, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}")->assertOk();
    }

    public function test_reception_cannot_create_room_types(): void
    {
        $hotel = Hotel::factory()->create();
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/room-types", [
            'name' => 'X', 'base_price' => 10, 'capacity' => 1,
        ])->assertStatus(403);
    }

    public function test_reception_cannot_update_room_types(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}", ['name' => 'Y'])
            ->assertStatus(403);
    }

    public function test_reception_cannot_activate_or_deactivate_room_types(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}/deactivate")
            ->assertStatus(403);
        $this->actingAs($reception, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}/activate")
            ->assertStatus(403);
    }

    public function test_guest_cannot_access_room_type_endpoints(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $guest = User::factory()->guest()->create();

        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/room-types")->assertStatus(403);
        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}")->assertStatus(403);
        $this->actingAs($guest, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/room-types", [
            'name' => 'X', 'base_price' => 10, 'capacity' => 1,
        ])->assertStatus(403);
    }

    // ── Room Type CRUD ──────────────────────────────────────────────

    public function test_list_room_types_is_paginated(): void
    {
        $hotel = Hotel::factory()->create();
        RoomType::factory()->count(3)->sequence(
            ['name' => 'Type A'], ['name' => 'Type B'], ['name' => 'Type C'],
        )->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/room-types")
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_show_returns_the_requested_room_type(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $roomType->id)
            ->assertJsonPath('data.name', $roomType->name);
    }

    public function test_create_room_type_persists_and_audits(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/room-types", [
            'name' => 'Executive Suite',
            'base_price' => 400,
            'capacity' => 4,
            'amenities' => ['wifi', 'minibar'],
            'description' => 'Spacious suite.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Created successfully.')
            ->assertJsonPath('data.name', 'Executive Suite')
            ->assertJsonPath('data.hotel_id', $hotel->id)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('room_types', ['hotel_id' => $hotel->id, 'name' => 'Executive Suite']);
        $this->assertNotNull(AuditLog::where('action', 'room-type.created')->first());
    }

    public function test_update_room_type(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'capacity' => 2]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}", ['capacity' => 5])
            ->assertOk()
            ->assertJsonPath('data.capacity', 5);

        $this->assertNotNull(AuditLog::where('action', 'room-type.updated')->first());
    }

    /**
     * Regression test for Phase 2D bug #1: RoomTypeService::update()
     * used to discard the repository's refreshed, count-loaded return
     * value, so these came back as {} instead of integers.
     */
    public function test_update_room_type_response_contains_integer_inventory_counts(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}", ['capacity' => 5])
            ->assertOk();

        $data = $response->json('data');
        $this->assertIsInt($data['rooms_count']);
        $this->assertIsInt($data['available_rooms_count']);
        $this->assertIsInt($data['maintenance_rooms_count']);
        $this->assertSame(1, $data['rooms_count']);
        $this->assertSame(1, $data['available_rooms_count']);
        $this->assertSame(0, $data['maintenance_rooms_count']);
    }

    /**
     * Regression test for Phase 2D bug #1 (activate path).
     */
    public function test_activate_response_contains_integer_inventory_counts(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->inactive()->create(['hotel_id' => $hotel->id]);
        Room::factory()->underMaintenance()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}/activate")
            ->assertOk();

        $data = $response->json('data');
        $this->assertIsInt($data['rooms_count']);
        $this->assertIsInt($data['available_rooms_count']);
        $this->assertIsInt($data['maintenance_rooms_count']);
        $this->assertSame(1, $data['rooms_count']);
        $this->assertSame(1, $data['maintenance_rooms_count']);
    }

    /**
     * Regression test for Phase 2D bug #1 (deactivate path).
     */
    public function test_deactivate_response_contains_integer_inventory_counts(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);
        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}/deactivate")
            ->assertOk();

        $data = $response->json('data');
        $this->assertIsInt($data['rooms_count']);
        $this->assertIsInt($data['available_rooms_count']);
        $this->assertIsInt($data['maintenance_rooms_count']);
        $this->assertSame(2, $data['rooms_count']);
        $this->assertSame(2, $data['available_rooms_count']);
    }

    public function test_is_active_cannot_be_changed_through_the_generic_update_endpoint(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}", ['is_active' => false, 'capacity' => 3])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.capacity', 3);
    }

    public function test_activate_and_deactivate_endpoints(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertNotNull(AuditLog::where('action', 'room-type.deactivated')->first());

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertNotNull(AuditLog::where('action', 'room-type.activated')->first());
    }

    public function test_deactivating_a_room_type_does_not_delete_or_change_its_rooms(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => 'available']);
    }

    public function test_inventory_counts_are_returned_correctly(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        Room::factory()->underMaintenance()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$roomType->id}")
            ->assertOk()
            ->assertJsonPath('data.rooms_count', 3)
            ->assertJsonPath('data.available_rooms_count', 2)
            ->assertJsonPath('data.maintenance_rooms_count', 1);
    }

    public function test_duplicate_room_type_name_within_the_same_hotel_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Deluxe Double']);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/room-types", [
            'name' => 'Deluxe Double', 'base_price' => 100, 'capacity' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_the_same_name_is_allowed_across_different_hotels(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        RoomType::factory()->create(['hotel_id' => $hotelA->id, 'name' => 'Deluxe Double']);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotelB->id}/room-types", [
            'name' => 'Deluxe Double', 'base_price' => 100, 'capacity' => 2,
        ])->assertCreated();
    }

    public function test_hotel_id_cannot_be_forced_via_request_body(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/room-types", [
            'hotel_id' => $otherHotel->id,
            'name' => 'Deluxe Double',
            'base_price' => 100,
            'capacity' => 2,
        ]);

        $response->assertCreated()->assertJsonPath('data.hotel_id', $hotel->id);
    }

    public function test_a_room_type_in_hotel_a_cannot_be_accessed_through_hotel_bs_route(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotelA->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/room-types/{$roomTypeA->id}")
            ->assertStatus(404);
    }

    public function test_validation_error_follows_the_standard_envelope(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/room-types", [])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors'])
            ->assertJson(['success' => false]);
    }

    public function test_authorization_error_follows_the_standard_envelope(): void
    {
        $hotel = Hotel::factory()->create();
        $guest = User::factory()->guest()->create();

        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/room-types")
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'This action is unauthorized.']);
    }

    public function test_not_found_follows_the_standard_envelope(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/room-types/999999")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    /**
     * Regression test for Phase 2D bug #2: implicit route model binding
     * on a genuinely missing id used to leak Eloquent's raw exception
     * message ("No query results for model [...] 999999") instead of
     * the standard localized not-found message.
     */
    public function test_implicit_route_model_binding_404_returns_the_standard_message_without_leaking_internals(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/room-types/999999")
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => 'The requested resource was not found.',
            ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('RoomType', $body);
        $this->assertStringNotContainsString('App\\Domain', $body);
        $this->assertStringNotContainsString('No query results', $body);
    }
}
