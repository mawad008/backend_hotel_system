<?php

namespace Tests\Feature\Guest;

use App\Domain\Discovery\Models\GuestFavoriteRoomType;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use Tests\TestCase;

class GuestFavoriteRoomTypeTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function roomType(bool $active = true, bool $hotelActive = true): RoomType
    {
        $hotel = Hotel::factory()->create(['is_active' => $hotelActive]);

        return RoomType::factory()->create(['hotel_id' => $hotel->id, 'is_active' => $active]);
    }

    public function test_favorites_require_a_guest_token(): void
    {
        $this->getJson('/api/v1/guest/favorites/rooms')->assertStatus(401);
        $this->putJson('/api/v1/guest/favorites/rooms/1')->assertStatus(401);
        $this->deleteJson('/api/v1/guest/favorites/rooms/1')->assertStatus(401);
    }

    public function test_guest_saves_lists_and_removes_a_room_idempotently(): void
    {
        $guest = $this->actingGuest();
        $roomType = $this->roomType();

        $this->putJson("/api/v1/guest/favorites/rooms/{$roomType->id}")
            ->assertOk()
            ->assertJsonPath('data.room_type_id', $roomType->id)
            ->assertJsonPath('data.hotel_id', $roomType->hotel_id)
            ->assertJsonPath('data.name', $roomType->name);
        $this->putJson("/api/v1/guest/favorites/rooms/{$roomType->id}")->assertOk();
        $this->assertDatabaseCount('guest_favorite_room_types', 1);

        $this->getJson('/api/v1/guest/favorites/rooms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.room_type_id', $roomType->id)
            ->assertJsonPath('data.0.hotel_id', $roomType->hotel_id)
            ->assertJsonPath('data.0.cover_url', null);

        $this->deleteJson("/api/v1/guest/favorites/rooms/{$roomType->id}")->assertOk();
        $this->deleteJson("/api/v1/guest/favorites/rooms/{$roomType->id}")->assertOk();
        $this->assertDatabaseMissing('guest_favorite_room_types', ['guest_id' => $guest->id]);
    }

    public function test_an_inactive_missing_or_hidden_hotel_room_cannot_be_saved(): void
    {
        $this->actingGuest();
        $inactive = $this->roomType(active: false);
        $hiddenHotel = $this->roomType(hotelActive: false);

        $this->putJson("/api/v1/guest/favorites/rooms/{$inactive->id}")->assertStatus(404);
        $this->putJson("/api/v1/guest/favorites/rooms/{$hiddenHotel->id}")->assertStatus(404);
        $this->putJson('/api/v1/guest/favorites/rooms/999999')->assertStatus(404);
        $this->assertDatabaseCount('guest_favorite_room_types', 0);
    }

    public function test_list_hides_rooms_that_became_unavailable_and_other_guests_rows(): void
    {
        $this->actingGuest();
        $kept = $this->roomType();
        $deactivated = $this->roomType();
        $hotelClosed = $this->roomType();
        foreach ([$kept, $deactivated, $hotelClosed] as $roomType) {
            $this->putJson("/api/v1/guest/favorites/rooms/{$roomType->id}")->assertOk();
        }
        $deactivated->update(['is_active' => false]);
        Hotel::query()->whereKey($hotelClosed->hotel_id)->update(['is_active' => false]);

        $other = Guest::factory()->create();
        GuestFavoriteRoomType::query()->create(['guest_id' => $other->id, 'room_type_id' => $kept->id]);

        $this->getJson('/api/v1/guest/favorites/rooms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.room_type_id', $kept->id);
    }
}
