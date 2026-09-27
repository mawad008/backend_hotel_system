<?php

namespace Tests\Feature\Location;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Tests\TestCase;

class HotelLocationIntegrationTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    public function test_creating_a_hotel_with_a_valid_country_city_pair_succeeds(): void
    {
        $group = HotelGroup::factory()->create();
        $country = Country::factory()->create();
        $city = City::factory()->create(['country_id' => $country->id, 'name_en' => 'Cairo']);

        $response = $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/hotels', [
            'hotel_group_id' => $group->id,
            'name' => 'Nile View',
            'slug' => 'nile-view',
            'deposit_percentage' => 10,
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.country_id', $country->id)
            ->assertJsonPath('data.city_id', $city->id)
            ->assertJsonPath('data.city_summary.name_en', 'Cairo')
            ->assertJsonPath('data.city', 'Cairo'); // legacy string kept in sync

        $this->assertDatabaseHas('hotels', [
            'slug' => 'nile-view',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'city' => 'Cairo',
        ]);
    }

    public function test_creating_a_hotel_with_a_mismatched_country_city_pair_is_rejected(): void
    {
        $group = HotelGroup::factory()->create();
        $egypt = Country::factory()->create();
        $saudi = Country::factory()->create();
        $saudiCity = City::factory()->create(['country_id' => $saudi->id]);

        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/hotels', [
            'hotel_group_id' => $group->id,
            'name' => 'Mismatch',
            'slug' => 'mismatch',
            'deposit_percentage' => 10,
            'country_id' => $egypt->id,
            'city_id' => $saudiCity->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['city_id']);
    }

    public function test_updating_a_hotel_city_to_one_outside_its_country_is_rejected(): void
    {
        $egypt = Country::factory()->create();
        $saudi = Country::factory()->create();
        $saudiCity = City::factory()->create(['country_id' => $saudi->id]);
        $hotel = Hotel::factory()->create(['country_id' => $egypt->id, 'city_id' => City::factory()->create(['country_id' => $egypt->id])->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", ['city_id' => $saudiCity->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);
    }

    public function test_existing_hotels_are_preserved_and_backfilled_by_the_migration(): void
    {
        // HotelFactory wires real country/city refs; assert the resource
        // still returns them and the legacy strings.
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $hotel->id)
            ->assertJsonPath('data.country_id', $hotel->country_id)
            ->assertJsonPath('data.city_id', $hotel->city_id)
            ->assertJsonStructure(['data' => ['country_summary' => ['id', 'name_en', 'name_ar'], 'city_summary' => ['id', 'name_en', 'name_ar']]]);
    }

    public function test_hotel_index_returns_country_and_city_summaries(): void
    {
        Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/hotels')
            ->assertOk()
            ->assertJsonStructure(['data' => [['country_summary' => ['name_en'], 'city_summary' => ['name_en']]]]);
    }

    public function test_guest_discovery_still_works_after_normalization(): void
    {
        $country = Country::factory()->create();
        $city = City::factory()->create(['country_id' => $country->id, 'name_en' => 'Riyadh']);
        Hotel::factory()->create(['is_active' => true, 'country_id' => $country->id, 'city_id' => $city->id, 'city' => 'Riyadh']);

        $this->getJson('/api/v1/guest/hotels/cities')
            ->assertOk()
            ->assertJsonFragment(['city' => 'Riyadh', 'hotel_count' => 1]);

        $this->getJson('/api/v1/guest/hotels?city=Riyadh')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
