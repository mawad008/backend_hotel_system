<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceCategory;
use App\Domain\StayServices\Models\ServiceReview;
use Tests\TestCase;

class GuestServiceCatalogTest extends TestCase
{
    public function test_anonymous_read_lists_only_active_categories_and_services(): void
    {
        $hotel = Hotel::factory()->create();
        ServiceCategory::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);
        ServiceCategory::factory()->create(['hotel_id' => $hotel->id, 'is_active' => false]);
        HotelService::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);
        HotelService::factory()->create(['hotel_id' => $hotel->id, 'is_active' => false]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/service-categories")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/services")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_inactive_hotel_is_a_plain_404(): void
    {
        $hotel = Hotel::factory()->create(['is_active' => false]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/services")->assertStatus(404);
        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/service-categories")->assertStatus(404);
    }

    public function test_a_missing_hotel_is_a_plain_404(): void
    {
        $this->getJson('/api/v1/guest/hotels/999999/services')->assertStatus(404);
    }

    public function test_service_rating_is_the_average_of_published_service_reviews_only(): void
    {
        $hotel = Hotel::factory()->create();
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);

        ServiceReview::factory()->create(['service_id' => $service->id, 'hotel_id' => $hotel->id, 'rating' => 5, 'status' => ServiceReview::STATUS_PUBLISHED]);
        ServiceReview::factory()->create(['service_id' => $service->id, 'hotel_id' => $hotel->id, 'rating' => 3, 'status' => ServiceReview::STATUS_PUBLISHED]);
        // Must not move the average: pending/rejected are excluded.
        ServiceReview::factory()->create(['service_id' => $service->id, 'hotel_id' => $hotel->id, 'rating' => 1, 'status' => ServiceReview::STATUS_PENDING]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/services")
            ->assertOk()
            ->assertJsonPath('data.0.rating', '4.00')
            ->assertJsonPath('data.0.reviews_count', 2);
    }

    public function test_a_service_with_no_published_reviews_has_a_null_rating_and_zero_count(): void
    {
        $hotel = Hotel::factory()->create();
        HotelService::factory()->create(['hotel_id' => $hotel->id]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/services")
            ->assertOk()
            ->assertJsonPath('data.0.rating', null)
            ->assertJsonPath('data.0.reviews_count', 0);
    }
}
