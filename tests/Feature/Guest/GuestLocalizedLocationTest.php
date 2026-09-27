<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Tests\TestCase;

/**
 * The guest API keeps `city` / `country` as the legacy English strings (and
 * `city` as the filter key) but adds display names in the request locale.
 */
class GuestLocalizedLocationTest extends TestCase
{
    private function jeddahHotel(): Hotel
    {
        $country = Country::factory()->create(['name_en' => 'Saudi Arabia', 'name_ar' => 'المملكة العربية السعودية']);
        $city = City::factory()->create(['country_id' => $country->id, 'name_en' => 'Jeddah', 'name_ar' => 'جدة']);

        return Hotel::factory()->create([
            'is_active' => true,
            'country_id' => $country->id,
            'city_id' => $city->id,
            'country' => 'Saudi Arabia',
            'city' => 'Jeddah',
        ]);
    }

    public function test_hotel_detail_and_list_carry_localized_city_and_country(): void
    {
        $hotel = $this->jeddahHotel();

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}", ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.city', 'Jeddah')
            ->assertJsonPath('data.city_name', 'جدة')
            ->assertJsonPath('data.country_name', 'المملكة العربية السعودية');

        $this->getJson('/api/v1/guest/hotels', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.0.city_name', 'Jeddah')
            ->assertJsonPath('data.0.country_name', 'Saudi Arabia');
    }

    public function test_cities_list_is_localized_and_keeps_the_filter_key(): void
    {
        $this->jeddahHotel();

        $this->getJson('/api/v1/guest/hotels/cities', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.0.city', 'Jeddah')
            ->assertJsonPath('data.0.name', 'جدة');

        $this->getJson('/api/v1/guest/hotels?city=Jeddah')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_matches_the_arabic_city_name(): void
    {
        $this->jeddahHotel();

        $this->getJson('/api/v1/guest/hotels?q='.urlencode('جدة'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
