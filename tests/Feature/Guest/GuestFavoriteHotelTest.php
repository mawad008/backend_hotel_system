<?php

namespace Tests\Feature\Guest;

use App\Domain\Discovery\Models\GuestFavoriteHotel;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\Guest;
use Tests\TestCase;

class GuestFavoriteHotelTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    public function test_favorites_require_a_guest_token(): void
    {
        $this->getJson('/api/v1/guest/favorites/hotels')->assertStatus(401);
        $this->putJson('/api/v1/guest/favorites/hotels/1')->assertStatus(401);
    }

    public function test_guest_saves_lists_and_removes_a_favorite_idempotently(): void
    {
        $guest = $this->actingGuest();
        $hotel = Hotel::factory()->create(['is_active' => true]);

        $this->putJson("/api/v1/guest/favorites/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.hotel_id', $hotel->id);
        $this->putJson("/api/v1/guest/favorites/hotels/{$hotel->id}")->assertOk();
        $this->assertDatabaseCount('guest_favorite_hotels', 1);

        $this->getJson('/api/v1/guest/favorites/hotels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.hotel_id', $hotel->id);

        $this->deleteJson("/api/v1/guest/favorites/hotels/{$hotel->id}")->assertOk();
        $this->deleteJson("/api/v1/guest/favorites/hotels/{$hotel->id}")->assertOk();
        $this->assertDatabaseMissing('guest_favorite_hotels', ['guest_id' => $guest->id]);
    }

    public function test_an_inactive_or_missing_hotel_cannot_be_saved(): void
    {
        $this->actingGuest();
        $inactive = Hotel::factory()->create(['is_active' => false]);

        $this->putJson("/api/v1/guest/favorites/hotels/{$inactive->id}")->assertStatus(404);
        $this->putJson('/api/v1/guest/favorites/hotels/999999')->assertStatus(404);
        $this->assertDatabaseCount('guest_favorite_hotels', 0);
    }

    public function test_list_hides_hotels_that_became_inactive_and_other_guests_rows(): void
    {
        $this->actingGuest();
        $kept = Hotel::factory()->create(['is_active' => true]);
        $hidden = Hotel::factory()->create(['is_active' => true]);
        $this->putJson("/api/v1/guest/favorites/hotels/{$kept->id}")->assertOk();
        $this->putJson("/api/v1/guest/favorites/hotels/{$hidden->id}")->assertOk();
        $hidden->update(['is_active' => false]);

        $other = Guest::factory()->create();
        GuestFavoriteHotel::query()->create(['guest_id' => $other->id, 'hotel_id' => $kept->id]);

        $this->getJson('/api/v1/guest/favorites/hotels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.hotel_id', $kept->id);
    }
}
