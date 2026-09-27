<?php

namespace Tests\Feature\Hotel;

use App\Domain\HotelGroup\Enums\HotelAmenity;
use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Tests\TestCase;

class HotelDiscoveryFieldsTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function locationPair(): array
    {
        $country = Country::factory()->create();
        $city = City::factory()->create(['country_id' => $country->id, 'name_en' => 'Cairo']);

        return ['country_id' => $country->id, 'city_id' => $city->id];
    }

    public function test_staff_can_create_a_hotel_with_i18n_copy_star_rating_and_facilities(): void
    {
        $group = HotelGroup::factory()->create();
        $pool = Facility::query()->where('key', HotelAmenity::Pool->value)->firstOrFail();
        $wifi = Facility::query()->where('key', HotelAmenity::FreeWifi->value)->firstOrFail();

        $res = $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/hotels', [
            'hotel_group_id' => $group->id,
            'slug' => 'nile-view',
            'deposit_percentage' => 10,
            'name_i18n' => ['en' => 'Nile View', 'ar' => 'إطلالة النيل'],
            'tagline_i18n' => ['en' => 'On the water', 'ar' => 'على ضفاف النهر'],
            'description_i18n' => ['en' => 'A calm riverside stay.', 'ar' => 'إقامة هادئة على النهر.'],
            'star_rating' => 5,
            'facility_ids' => [$pool->id, $wifi->id],
            ...$this->locationPair(),
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.name_i18n.ar', 'إطلالة النيل')
            ->assertJsonPath('data.star_rating', 5)
            ->assertJsonCount(2, 'data.facilities')
            // legacy `name` derived from the fallback-locale i18n value
            ->assertJsonPath('data.name', 'Nile View');

        $this->assertDatabaseHas('hotels', ['slug' => 'nile-view', 'name' => 'Nile View', 'star_rating' => 5]);
        $this->assertDatabaseHas('facility_hotel', ['facility_id' => $pool->id]);
    }

    public function test_star_rating_and_facility_ids_are_validated(): void
    {
        $group = HotelGroup::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/hotels', [
            'hotel_group_id' => $group->id,
            'name' => 'X',
            'slug' => 'x-hotel',
            'deposit_percentage' => 10,
            'star_rating' => 9,
            'facility_ids' => [999999],
            ...$this->locationPair(),
        ])->assertStatus(422)->assertJsonValidationErrors(['star_rating', 'facility_ids.0']);
    }

    public function test_staff_resource_exposes_raw_i18n_maps_for_editing(): void
    {
        $hotel = Hotel::factory()->create([
            'name' => 'Legacy Only',
            'name_i18n' => ['en' => 'Legacy Only', 'ar' => 'الاسم القديم'],
            'star_rating' => 3,
        ]);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.name_i18n.ar', 'الاسم القديم')
            ->assertJsonPath('data.star_rating', 3)
            ->assertJsonStructure(['data' => ['name_i18n', 'tagline_i18n', 'description_i18n', 'facilities', 'gallery', 'meta_title_i18n', 'meta_description_i18n', 'seo_indexable']]);
    }

    public function test_updating_i18n_name_keeps_the_legacy_search_string_in_sync(): void
    {
        $hotel = Hotel::factory()->create(['name' => 'Before', 'slug' => 'sync-hotel']);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", [
                'name_i18n' => ['en' => 'After', 'ar' => 'بعد'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('hotels', ['id' => $hotel->id, 'name' => 'After']);
    }
}
