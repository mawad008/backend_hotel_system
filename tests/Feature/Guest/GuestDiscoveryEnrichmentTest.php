<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Enums\HotelAmenity;
use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelMedia;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Review\Models\Review;
use Tests\TestCase;

class GuestDiscoveryEnrichmentTest extends TestCase
{
    public function test_hotel_name_and_copy_resolve_to_the_request_locale(): void
    {
        $hotel = Hotel::factory()->create([
            'name' => 'Nile View',
            'name_i18n' => ['en' => 'Nile View', 'ar' => 'إطلالة النيل'],
            'tagline_i18n' => ['en' => 'On the water', 'ar' => 'على ضفاف النهر'],
            'star_rating' => 5,
        ]);
        // `amenities` is sourced from the Facility relation (see the Hotel
        // module's Facilities migration) — each entry is `{key, label,
        // icon}`, `label` resolved to the request locale from the
        // facility's own `name_i18n`, so a client never needs a hardcoded
        // key->label map for an open, admin-managed catalog.
        $hotel->facilities()->sync(
            Facility::query()->whereIn('key', [HotelAmenity::Breakfast->value, HotelAmenity::Pool->value])->pluck('id')
        );

        $response = $this->getJson('/api/v1/guest/hotels', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'إطلالة النيل')
            ->assertJsonPath('data.0.tagline', 'على ضفاف النهر')
            ->assertJsonPath('data.0.star_rating', 5);

        $amenities = $response->json('data.0.amenities');
        $this->assertSame(
            [HotelAmenity::Breakfast->value, HotelAmenity::Pool->value],
            collect($amenities)->pluck('key')->sort()->values()->all(),
        );
        foreach ($amenities as $amenity) {
            $this->assertArrayHasKey('label', $amenity);
            $this->assertNotSame('', $amenity['label']);
            $this->assertArrayHasKey('icon', $amenity);
        }

        $this->getJson('/api/v1/guest/hotels', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Nile View');

        // A hotel with no i18n map falls back to the legacy `name` string.
        $legacy = Hotel::factory()->create(['name' => 'Old Wing', 'name_i18n' => null, 'city' => 'Aswan']);
        $this->getJson('/api/v1/guest/hotels?city=Aswan', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Old Wing');
    }

    public function test_hotel_list_exposes_price_from_and_room_type_count(): void
    {
        $hotel = Hotel::factory()->create(['city' => 'Giza']);
        RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => 700, 'is_active' => true]);
        RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => 400, 'is_active' => true]);
        RoomType::factory()->inactive()->create(['hotel_id' => $hotel->id, 'base_price' => 100]);

        $this->getJson('/api/v1/guest/hotels?city=Giza')
            ->assertOk()
            ->assertJsonPath('data.0.price_from', '400.00')
            ->assertJsonPath('data.0.room_types_count', 2);
    }

    public function test_hotel_list_and_detail_expose_media_urls(): void
    {
        $hotel = Hotel::factory()->create(['city' => 'Dahab']);
        HotelMedia::factory()->collection(HotelMedia::COLLECTION_LOGO)->create(['hotel_id' => $hotel->id, 'path' => 'hotels/x/logo/a.jpg']);
        HotelMedia::factory()->collection(HotelMedia::COLLECTION_COVER)->create(['hotel_id' => $hotel->id, 'path' => 'hotels/x/cover/b.jpg']);
        HotelMedia::factory()->collection(HotelMedia::COLLECTION_GALLERY)->count(2)->create(['hotel_id' => $hotel->id]);

        $this->getJson('/api/v1/guest/hotels?city=Dahab')
            ->assertOk()
            ->assertJsonPath('data.0.logo_url', fn ($v) => is_string($v) && str_contains($v, 'a.jpg'))
            ->assertJsonPath('data.0.cover_url', fn ($v) => is_string($v) && str_contains($v, 'b.jpg'));

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.gallery');
    }

    public function test_guest_discovery_never_exposes_raw_review_rows_or_booking_counts(): void
    {
        Hotel::factory()->create();

        $this->getJson('/api/v1/guest/hotels')
            ->assertOk()
            ->assertJsonMissingPath('data.0.reviews')
            ->assertJsonMissingPath('data.0.bookings_count');
    }

    public function test_hotel_rating_is_the_average_of_published_reviews_only(): void
    {
        $hotel = Hotel::factory()->create(['city' => 'Rating City']);

        Review::factory()->for($hotel)->create(['rating' => 5, 'status' => Review::STATUS_PUBLISHED]);
        Review::factory()->for($hotel)->create(['rating' => 3, 'status' => Review::STATUS_PUBLISHED]);
        // Must not move the average: pending/rejected are excluded.
        Review::factory()->for($hotel)->create(['rating' => 1, 'status' => Review::STATUS_PENDING]);
        Review::factory()->for($hotel)->create(['rating' => 1, 'status' => Review::STATUS_REJECTED]);

        $this->getJson('/api/v1/guest/hotels?city=Rating+City')
            ->assertOk()
            ->assertJsonPath('data.0.rating', '4.00')
            ->assertJsonPath('data.0.reviews_count', 2);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.rating', '4.00')
            ->assertJsonPath('data.reviews_count', 2);
    }

    public function test_hotel_with_no_published_reviews_has_a_null_rating(): void
    {
        $hotel = Hotel::factory()->create(['city' => 'No Reviews City']);
        Review::factory()->for($hotel)->create(['status' => Review::STATUS_PENDING]);

        $this->getJson('/api/v1/guest/hotels?city=No+Reviews+City')
            ->assertOk()
            ->assertJsonPath('data.0.rating', null)
            ->assertJsonPath('data.0.reviews_count', 0);
    }
}
