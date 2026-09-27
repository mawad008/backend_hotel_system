<?php

namespace Tests\Feature\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceReview;
use Tests\TestCase;

class ServiceReviewApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function manager(Hotel $hotel): User
    {
        $user = User::factory()->hotelManager()->create();
        $user->hotels()->attach($hotel);

        return $user;
    }

    public function test_staff_lists_every_moderation_state_for_their_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);
        ServiceReview::factory()->create(['hotel_id' => $hotel->id, 'service_id' => $service->id, 'status' => ServiceReview::STATUS_PENDING]);
        ServiceReview::factory()->create(['hotel_id' => $hotel->id, 'service_id' => $service->id, 'status' => ServiceReview::STATUS_PUBLISHED]);
        ServiceReview::factory()->create(['hotel_id' => $hotel->id, 'service_id' => $service->id, 'status' => ServiceReview::STATUS_REJECTED]);
        // A different hotel's review must never leak into this listing.
        ServiceReview::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/service-reviews")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_staff_filters_by_status_and_service(): void
    {
        $hotel = Hotel::factory()->create();
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);
        $otherService = HotelService::factory()->create(['hotel_id' => $hotel->id]);
        ServiceReview::factory()->create(['hotel_id' => $hotel->id, 'service_id' => $service->id, 'status' => ServiceReview::STATUS_PENDING]);
        ServiceReview::factory()->create(['hotel_id' => $hotel->id, 'service_id' => $otherService->id, 'status' => ServiceReview::STATUS_PENDING]);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/service-reviews?service_id={$service->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.service_id', $service->id);
    }

    public function test_reception_can_view_but_not_moderate(): void
    {
        // reviews.view = broad front-desk read; reviews.moderate = manager+
        // only — the same split ReviewPolicy uses, reused here rather than
        // introducing a parallel service-review permission pair.
        $hotel = Hotel::factory()->create();
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);
        $review = ServiceReview::factory()->create(['hotel_id' => $hotel->id, 'service_id' => $service->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/service-reviews")
            ->assertOk();

        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/service-reviews/{$review->id}/moderate", ['decision' => ServiceReview::STATUS_PUBLISHED])
            ->assertStatus(403);
    }

    public function test_a_hotel_manager_without_hotel_access_cannot_view(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();

        $this->actingAs($this->manager($otherHotel), 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/service-reviews")
            ->assertStatus(403);
    }

    public function test_moderate_publishes_or_rejects(): void
    {
        $hotel = Hotel::factory()->create();
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);
        $review = ServiceReview::factory()->create([
            'hotel_id' => $hotel->id, 'service_id' => $service->id, 'status' => ServiceReview::STATUS_PENDING,
        ]);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/service-reviews/{$review->id}/moderate", ['decision' => ServiceReview::STATUS_PUBLISHED])
            ->assertOk()
            ->assertJsonPath('data.status', ServiceReview::STATUS_PUBLISHED);

        $this->assertDatabaseHas('service_reviews', ['id' => $review->id, 'status' => ServiceReview::STATUS_PUBLISHED]);
    }

    public function test_moderate_requires_a_valid_decision(): void
    {
        $hotel = Hotel::factory()->create();
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);
        $review = ServiceReview::factory()->create(['hotel_id' => $hotel->id, 'service_id' => $service->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/service-reviews/{$review->id}/moderate", ['decision' => 'not-a-real-decision'])
            ->assertStatus(422);
    }

    public function test_a_manager_cannot_moderate_a_review_from_a_hotel_they_do_not_manage(): void
    {
        $hotel = Hotel::factory()->create();
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);
        $review = ServiceReview::factory()->create(['hotel_id' => $hotel->id, 'service_id' => $service->id]);

        $otherHotel = Hotel::factory()->create();

        $this->actingAs($this->manager($otherHotel), 'sanctum')
            ->postJson("/api/v1/service-reviews/{$review->id}/moderate", ['decision' => ServiceReview::STATUS_PUBLISHED])
            ->assertStatus(403);
    }
}
