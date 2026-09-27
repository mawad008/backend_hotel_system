<?php

namespace Tests\Feature\Review;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Review\Models\Review;
use Tests\TestCase;

class ReviewApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    public function test_staff_lists_every_moderation_state_for_their_hotel(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        Review::factory()->create(['hotel_id' => $hotel->id]);
        Review::factory()->published()->create(['hotel_id' => $hotel->id]);
        Review::factory()->rejected()->create(['hotel_id' => $hotel->id]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/reviews")
            ->assertOk();

        $response->assertJsonCount(3, 'data');
        $this->assertNotNull($response->json('data.0.hotel_id'));
    }

    public function test_staff_can_filter_by_status(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        Review::factory()->create(['hotel_id' => $hotel->id]);
        Review::factory()->published()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/reviews?status=".Review::STATUS_PUBLISHED)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_hotel_manager_moderates_a_review(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $review = Review::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reviews/{$review->id}/moderate", ['decision' => Review::STATUS_PUBLISHED])
            ->assertOk()
            ->assertJsonPath('data.status', Review::STATUS_PUBLISHED)
            ->assertJsonPath('data.hotel_id', $hotel->id);

        $this->assertSame(Review::STATUS_PUBLISHED, $review->fresh()->status);
        $this->assertSame($manager->id, $review->fresh()->moderated_by_user_id);
        $this->assertNotNull($review->fresh()->moderated_at);
    }

    public function test_moderation_decision_is_validated(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $review = Review::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reviews/{$review->id}/moderate", ['decision' => 'pending'])
            ->assertStatus(422);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reviews/{$review->id}/moderate", [])
            ->assertStatus(422);
    }

    public function test_reception_can_view_but_not_moderate(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $review = Review::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/reviews")
            ->assertOk();

        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/reviews/{$review->id}/moderate", ['decision' => Review::STATUS_PUBLISHED])
            ->assertStatus(403);
    }

    public function test_staff_from_another_hotel_cannot_view_or_moderate(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);
        $review = Review::factory()->create(['hotel_id' => $hotelB->id]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/reviews")
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reviews/{$review->id}/moderate", ['decision' => Review::STATUS_PUBLISHED])
            ->assertStatus(403);
    }

    public function test_guest_cannot_access_staff_review_endpoints(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $review = Review::factory()->create(['hotel_id' => $hotel->id]);

        $this->getJson("/api/v1/hotels/{$hotel->id}/reviews")->assertStatus(401);
        $this->postJson("/api/v1/reviews/{$review->id}/moderate", ['decision' => Review::STATUS_PUBLISHED])
            ->assertStatus(401);
    }
}
