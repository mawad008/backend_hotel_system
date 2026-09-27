<?php

namespace Tests\Feature\Hotel;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Tests\TestCase;

class HotelSeoFieldsTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function locationPair(): array
    {
        $country = Country::factory()->create();
        $city = City::factory()->create(['country_id' => $country->id]);

        return ['country_id' => $country->id, 'city_id' => $city->id];
    }

    public function test_staff_can_create_a_hotel_with_bilingual_seo_metadata(): void
    {
        $group = HotelGroup::factory()->create();

        $res = $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/hotels', [
            'hotel_group_id' => $group->id,
            'name' => 'Nile View',
            'slug' => 'nile-view-seo',
            'deposit_percentage' => 10,
            'meta_title_i18n' => ['en' => 'Nile View Hotel — Cairo', 'ar' => 'فندق إطلالة النيل - القاهرة'],
            'meta_description_i18n' => ['en' => 'A calm riverside stay in the heart of Cairo.', 'ar' => 'إقامة هادئة على ضفاف النيل في قلب القاهرة.'],
            'seo_indexable' => false,
            ...$this->locationPair(),
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.meta_title_i18n.en', 'Nile View Hotel — Cairo')
            ->assertJsonPath('data.meta_description_i18n.ar', 'إقامة هادئة على ضفاف النيل في قلب القاهرة.')
            ->assertJsonPath('data.seo_indexable', false);

        $this->assertDatabaseHas('hotels', ['slug' => 'nile-view-seo', 'seo_indexable' => false]);
    }

    public function test_seo_metadata_persists_through_an_update_and_reload(): void
    {
        $hotel = Hotel::factory()->create(['seo_indexable' => true]);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", [
                'meta_title_i18n' => ['en' => 'Updated Title'],
                'meta_description_i18n' => ['en' => 'Updated description.'],
                'seo_indexable' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.meta_title_i18n.en', 'Updated Title')
            ->assertJsonPath('data.seo_indexable', false);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.meta_title_i18n.en', 'Updated Title')
            ->assertJsonPath('data.meta_description_i18n.en', 'Updated description.')
            ->assertJsonPath('data.seo_indexable', false);
    }

    public function test_meta_title_and_description_enforce_search_engine_length_limits(): void
    {
        $group = HotelGroup::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/hotels', [
            'hotel_group_id' => $group->id,
            'name' => 'X',
            'slug' => 'x-seo-hotel',
            'deposit_percentage' => 10,
            'meta_title_i18n' => ['en' => str_repeat('a', 61)],
            'meta_description_i18n' => ['en' => str_repeat('b', 161)],
            ...$this->locationPair(),
        ])->assertStatus(422)->assertJsonValidationErrors(['meta_title_i18n.en', 'meta_description_i18n.en']);
    }

    public function test_guest_discovery_exposes_resolved_meta_title_and_description_and_indexable_flag(): void
    {
        $hotel = Hotel::factory()->create([
            'city' => 'Luxor',
            'meta_title_i18n' => ['en' => 'Guest SEO Title', 'ar' => 'عنوان السيو'],
            'meta_description_i18n' => ['en' => 'Guest SEO description.'],
            'seo_indexable' => false,
        ]);

        $this->getJson('/api/v1/guest/hotels?city=Luxor', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.0.meta_title', 'Guest SEO Title')
            ->assertJsonPath('data.0.meta_description', 'Guest SEO description.')
            ->assertJsonPath('data.0.seo_indexable', false);
    }
}
