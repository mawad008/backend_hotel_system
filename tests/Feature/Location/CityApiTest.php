<?php

namespace Tests\Feature\Location;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Tests\TestCase;

class CityApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function viewer(): User
    {
        return User::factory()->reception()->create();
    }

    public function test_list_cities_is_paginated_and_includes_country_summary(): void
    {
        $country = Country::factory()->create(['name_en' => 'Egypt']);
        City::factory()->count(2)->create(['country_id' => $country->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/cities')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'country_id', 'name_en', 'name_ar', 'is_active', 'country' => ['id', 'name_en', 'name_ar']]], 'meta' => ['total']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.country.name_en', 'Egypt');
    }

    public function test_cities_can_be_filtered_by_country(): void
    {
        $egypt = Country::factory()->create();
        $saudi = Country::factory()->create();
        City::factory()->create(['country_id' => $egypt->id, 'name_en' => 'Cairo']);
        City::factory()->create(['country_id' => $saudi->id, 'name_en' => 'Riyadh']);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/cities?country_id={$egypt->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name_en', 'Cairo');
    }

    public function test_cities_can_be_searched(): void
    {
        $country = Country::factory()->create();
        City::factory()->create(['country_id' => $country->id, 'name_en' => 'Cairo']);
        City::factory()->create(['country_id' => $country->id, 'name_en' => 'Luxor']);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/cities?search=lux')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name_en', 'Luxor');
    }

    public function test_country_scoped_cities_endpoint_only_returns_that_countrys_cities(): void
    {
        $egypt = Country::factory()->create();
        $saudi = Country::factory()->create();
        City::factory()->create(['country_id' => $egypt->id, 'name_en' => 'Cairo']);
        City::factory()->create(['country_id' => $egypt->id, 'name_en' => 'Alexandria']);
        City::factory()->create(['country_id' => $saudi->id, 'name_en' => 'Riyadh']);

        $response = $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/countries/{$egypt->id}/cities")
            ->assertOk();

        $names = array_column($response->json('data'), 'name_en');
        sort($names);
        $this->assertSame(['Alexandria', 'Cairo'], $names);
    }

    public function test_create_city(): void
    {
        $country = Country::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/cities', [
            'country_id' => $country->id,
            'name_en' => 'Cairo',
            'name_ar' => 'القاهرة',
        ])->assertCreated()
            ->assertJsonPath('data.name_en', 'Cairo')
            ->assertJsonPath('data.country_id', $country->id)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('cities', ['country_id' => $country->id, 'name_en' => 'Cairo']);
        $this->assertNotNull(AuditLog::where('action', 'city.created')->first());
    }

    public function test_duplicate_city_name_within_the_same_country_is_rejected(): void
    {
        $country = Country::factory()->create();
        City::factory()->create(['country_id' => $country->id, 'name_en' => 'Cairo', 'name_ar' => 'القاهرة']);

        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/cities', [
            'country_id' => $country->id, 'name_en' => 'Cairo', 'name_ar' => 'القاهرة',
        ])->assertStatus(422)->assertJsonValidationErrors(['name_en', 'name_ar']);
    }

    public function test_the_same_city_name_is_allowed_across_different_countries(): void
    {
        $egypt = Country::factory()->create();
        $other = Country::factory()->create();
        City::factory()->create(['country_id' => $egypt->id, 'name_en' => 'Alexandria', 'name_ar' => 'الإسكندرية']);

        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/cities', [
            'country_id' => $other->id, 'name_en' => 'Alexandria', 'name_ar' => 'الإسكندرية',
        ])->assertCreated();
    }

    public function test_create_city_rejects_an_unknown_country(): void
    {
        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/cities', [
            'country_id' => 999999, 'name_en' => 'Nowhere', 'name_ar' => 'لا مكان',
        ])->assertStatus(422)->assertJsonValidationErrors(['country_id']);
    }

    public function test_update_city_can_move_it_to_another_country(): void
    {
        $egypt = Country::factory()->create();
        $saudi = Country::factory()->create();
        $city = City::factory()->create(['country_id' => $egypt->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/cities/{$city->id}", ['country_id' => $saudi->id])
            ->assertOk()
            ->assertJsonPath('data.country_id', $saudi->id);
    }

    public function test_activate_and_deactivate_city(): void
    {
        $city = City::factory()->create(['is_active' => true]);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/cities/{$city->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/cities/{$city->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_city_with_no_hotels_can_be_deleted(): void
    {
        $city = City::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/cities/{$city->id}")
            ->assertOk();

        $this->assertDatabaseMissing('cities', ['id' => $city->id]);
    }

    public function test_city_referenced_by_a_hotel_cannot_be_deleted(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/cities/{$hotel->city_id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('cities', ['id' => $hotel->city_id]);
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        $viewer = $this->viewer();
        $country = Country::factory()->create();
        $city = City::factory()->create(['country_id' => $country->id]);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/cities')->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/countries/{$country->id}/cities")->assertOk();

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/cities', [
            'country_id' => $country->id, 'name_en' => 'X', 'name_ar' => 'X',
        ])->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')->putJson("/api/v1/cities/{$city->id}", ['name_en' => 'Y'])->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')->deleteJson("/api/v1/cities/{$city->id}")->assertStatus(403);
    }

    public function test_user_without_locations_view_has_no_access(): void
    {
        $guest = User::factory()->guest()->create();

        $this->actingAs($guest, 'sanctum')->getJson('/api/v1/cities')->assertStatus(403);
    }
}
