<?php

namespace Tests\Feature\Review;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Review\Models\Review;
use App\Domain\Review\Models\ReviewCategory;
use App\Domain\Review\Models\ReviewCategoryRating;
use Tests\TestCase;

/**
 * Dynamic, per-hotel review categories: dashboard management, the guest
 * review flow built on them, snapshots, and the live analytics.
 */
class ReviewCategoryTest extends TestCase
{
    private function hotel(): Hotel
    {
        return Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
    }

    private function managerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    private function completedReservation(Hotel $hotel, ?Guest $guest = null): Reservation
    {
        $guest ??= Guest::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_INVOICED, 'price_snapshot' => '200.00',
        ]);
    }

    /** Submits a review as the reservation's guest; returns the response. */
    private function submitAsGuest(Reservation $reservation, array $payload)
    {
        $guest = Guest::query()->findOrFail($reservation->guest_id);
        // Tests reuse one app instance: drop the guard's cached user so this
        // request authenticates as *this* reservation's guest.
        $this->app['auth']->forgetGuards();

        return $this->withToken($guest->createToken('guest-api')->plainTextToken)
            ->postJson("/api/v1/guest/reservations/{$reservation->id}/review", $payload);
    }

    private function publish(Reservation $reservation): void
    {
        Review::query()->where('reservation_id', $reservation->id)->update(['status' => Review::STATUS_PUBLISHED]);
    }

    public function test_admin_creates_a_category_for_hotel_a_and_it_does_not_appear_for_hotel_b(): void
    {
        $hotelA = $this->hotel();
        $hotelB = $this->hotel();

        $this->actingAs($this->managerFor($hotelA), 'sanctum')
            ->postJson("/api/v1/hotels/{$hotelA->id}/review-categories", [
                'name' => 'النظافة', 'name_ar' => 'النظافة', 'name_en' => 'Cleanliness', 'icon' => 'sparkles',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.hotel_id', $hotelA->id)
            ->assertJsonPath('data.name_en', 'Cleanliness')
            ->assertJsonPath('data.is_active', true);

        $this->getJson("/api/v1/guest/hotels/{$hotelA->id}/review-categories")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/guest/hotels/{$hotelB->id}/review-categories")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_different_hotels_can_have_completely_different_categories(): void
    {
        $hotelA = $this->hotel();
        $hotelB = $this->hotel();
        foreach (['النظافة', 'الراحة', 'الموقع', 'الخدمة'] as $i => $name) {
            ReviewCategory::factory()->create(['hotel_id' => $hotelA->id, 'name' => $name, 'sort_order' => $i]);
        }
        foreach (['جودة الغرف', 'الإفطار', 'النظافة', 'تعامل الموظفين', 'الموقع'] as $i => $name) {
            ReviewCategory::factory()->create(['hotel_id' => $hotelB->id, 'name' => $name, 'sort_order' => $i]);
        }

        $a = $this->getJson("/api/v1/guest/hotels/{$hotelA->id}/review-categories")->assertOk();
        $b = $this->getJson("/api/v1/guest/hotels/{$hotelB->id}/review-categories")->assertOk();

        $this->assertSame(['النظافة', 'الراحة', 'الموقع', 'الخدمة'], array_column($a->json('data'), 'name'));
        $this->assertSame(['جودة الغرف', 'الإفطار', 'النظافة', 'تعامل الموظفين', 'الموقع'], array_column($b->json('data'), 'name'));
    }

    public function test_guest_receives_only_active_categories_in_display_order_with_localized_label(): void
    {
        $hotel = $this->hotel();
        ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'B', 'name_ar' => 'ب', 'name_en' => 'Bee', 'sort_order' => 2]);
        ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'A', 'name_ar' => 'أ', 'name_en' => 'Ay', 'sort_order' => 1]);
        ReviewCategory::factory()->inactive()->create(['hotel_id' => $hotel->id, 'name' => 'Hidden']);

        $response = $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/guest/hotels/{$hotel->id}/review-categories")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(['A', 'B'], array_column($response->json('data'), 'name'));
        $this->assertSame('أ', $response->json('data.0.label'));
    }

    public function test_guest_submits_ratings_for_dynamic_categories_and_they_are_stored_with_a_snapshot(): void
    {
        $hotel = $this->hotel();
        $clean = ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'النظافة', 'name_en' => 'Cleanliness']);
        $breakfast = ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'الإفطار', 'name_en' => 'Breakfast']);
        $reservation = $this->completedReservation($hotel);

        $this->submitAsGuest($reservation, [
            'rating' => 4,
            'text' => 'جيد',
            'category_ratings' => [
                ['category_id' => $clean->id, 'rating' => 5],
                ['category_id' => $breakfast->id, 'rating' => 3],
            ],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.rating', 4)
            ->assertJsonCount(2, 'data.category_ratings')
            ->assertJsonPath('data.category_ratings.0.category_id', $clean->id)
            ->assertJsonPath('data.category_ratings.0.rating', 5);

        $review = Review::query()->where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame(2, $review->categoryRatings()->count());
        $stored = ReviewCategoryRating::query()->where('review_id', $review->id)->where('review_category_id', $breakfast->id)->firstOrFail();
        $this->assertSame(3, $stored->rating);
        $this->assertSame('الإفطار', $stored->category_name);
        $this->assertSame('Breakfast', $stored->category_name_en);
    }

    public function test_a_category_of_another_hotel_or_a_disabled_one_is_rejected(): void
    {
        $hotel = $this->hotel();
        $otherHotelCategory = ReviewCategory::factory()->create(['hotel_id' => $this->hotel()->id]);
        $disabled = ReviewCategory::factory()->inactive()->create(['hotel_id' => $hotel->id]);

        $this->submitAsGuest($this->completedReservation($hotel), [
            'rating' => 5, 'category_ratings' => [['category_id' => $otherHotelCategory->id, 'rating' => 5]],
        ])->assertStatus(422)->assertJsonPath('errors.reason', "invalid_category:{$otherHotelCategory->id}");

        $this->submitAsGuest($this->completedReservation($hotel), [
            'rating' => 5, 'category_ratings' => [['category_id' => $disabled->id, 'rating' => 5]],
        ])->assertStatus(422);

        $this->assertSame(0, Review::query()->count());
    }

    public function test_category_ratings_are_validated(): void
    {
        $hotel = $this->hotel();
        $category = ReviewCategory::factory()->create(['hotel_id' => $hotel->id]);

        $this->submitAsGuest($this->completedReservation($hotel), [
            'rating' => 5, 'category_ratings' => [['category_id' => $category->id, 'rating' => 6]],
        ])->assertStatus(422);

        $this->submitAsGuest($this->completedReservation($hotel), [
            'rating' => 5, 'category_ratings' => [
                ['category_id' => $category->id, 'rating' => 5],
                ['category_id' => $category->id, 'rating' => 4],
            ],
        ])->assertStatus(422);
    }

    public function test_a_disabled_category_does_not_appear_in_new_reviews(): void
    {
        $hotel = $this->hotel();
        $category = ReviewCategory::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($this->managerFor($hotel), 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/review-categories/{$category->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/review-categories")->assertOk()->assertJsonCount(0, 'data');
        $this->submitAsGuest($this->completedReservation($hotel), [
            'rating' => 5, 'category_ratings' => [['category_id' => $category->id, 'rating' => 5]],
        ])->assertStatus(422);
    }

    public function test_existing_reviews_remain_valid_after_the_category_is_renamed_or_disabled(): void
    {
        $hotel = $this->hotel();
        $category = ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'الخدمة', 'name_en' => 'Service']);
        $reservation = $this->completedReservation($hotel);
        $this->submitAsGuest($reservation, [
            'rating' => 4, 'category_ratings' => [['category_id' => $category->id, 'rating' => 4]],
        ])->assertStatus(201);

        $manager = $this->managerFor($hotel);
        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/review-categories/{$category->id}", ['name' => 'تعامل الموظفين', 'name_en' => 'Staff'])
            ->assertOk();
        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/review-categories/{$category->id}/deactivate")
            ->assertOk();

        // The stored rating still reads as the guest rated it…
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/reviews")
            ->assertOk()
            ->assertJsonPath('data.0.category_ratings.0.category_id', $category->id)
            ->assertJsonPath('data.0.category_ratings.0.name', 'الخدمة')
            ->assertJsonPath('data.0.category_ratings.0.name_en', 'Service')
            ->assertJsonPath('data.0.category_ratings.0.rating', 4);

        // …and it still counts toward the (renamed) category's analytics.
        $this->publish($reservation);
        $analytics = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/reviews/analytics")
            ->assertOk();
        $this->assertSame('تعامل الموظفين', $analytics->json('data.categories.0.name'));
        $this->assertSame(1, $analytics->json('data.categories.0.ratings_count'));
    }

    public function test_hotel_averages_are_calculated_from_published_reviews(): void
    {
        $hotel = $this->hotel();
        $clean = ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'النظافة', 'sort_order' => 0]);
        $location = ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'الموقع', 'sort_order' => 1]);
        $unrated = ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'الإفطار', 'sort_order' => 2]);

        $r1 = $this->completedReservation($hotel);
        $this->submitAsGuest($r1, ['rating' => 5, 'category_ratings' => [
            ['category_id' => $clean->id, 'rating' => 5], ['category_id' => $location->id, 'rating' => 4],
        ]])->assertStatus(201);
        $r2 = $this->completedReservation($hotel);
        $this->submitAsGuest($r2, ['rating' => 3, 'category_ratings' => [
            ['category_id' => $clean->id, 'rating' => 4],
        ]])->assertStatus(201);
        // A pending review never counts toward a published average.
        $r3 = $this->completedReservation($hotel);
        $this->submitAsGuest($r3, ['rating' => 1, 'category_ratings' => [
            ['category_id' => $clean->id, 'rating' => 1],
        ]])->assertStatus(201);
        $this->publish($r1);
        $this->publish($r2);

        $manager = $this->managerFor($hotel);
        $analytics = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/reviews/analytics")
            ->assertOk()
            ->assertJsonPath('data.total_reviews', 3)
            ->assertJsonPath('data.published_count', 2)
            ->assertJsonPath('data.by_status.pending', 1);

        $this->assertEqualsWithDelta(4.0, $analytics->json('data.average'), 0.001);
        $rows = collect($analytics->json('data.categories'))->keyBy('id');
        $this->assertEqualsWithDelta(4.5, $rows[$clean->id]['average'], 0.001);
        $this->assertSame(2, $rows[$clean->id]['ratings_count']);
        $this->assertEqualsWithDelta(4.0, $rows[$location->id]['average'], 0.001);
        $this->assertSame(1, $rows[$location->id]['ratings_count']);
        // A newly added category shows up automatically, just unrated.
        $this->assertNull($rows[$unrated->id]['average']);
        $this->assertSame(0, $rows[$unrated->id]['ratings_count']);

        // The public hotel detail carries the same live summary.
        $detail = $this->getJson("/api/v1/guest/hotels/{$hotel->id}")->assertOk();
        $this->assertSame(2, $detail->json('data.review_summary.count'));
        $this->assertCount(3, $detail->json('data.review_summary.categories'));
        $this->assertEqualsWithDelta(4.5, $detail->json('data.review_summary.categories.0.average'), 0.001);
    }

    public function test_dashboard_returns_every_guest_review_with_its_category_ratings(): void
    {
        $hotel = $this->hotel();
        $category = ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'الراحة']);
        $guest = Guest::factory()->create(['name' => 'Ahmed']);
        $this->submitAsGuest($this->completedReservation($hotel, $guest), [
            'rating' => 5, 'text' => 'ممتاز', 'category_ratings' => [['category_id' => $category->id, 'rating' => 4]],
        ])->assertStatus(201);
        $this->submitAsGuest($this->completedReservation($hotel), ['rating' => 2])->assertStatus(201);

        $response = $this->actingAs($this->managerFor($hotel), 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ahmed = collect($response->json('data'))->firstWhere('guest.name', 'Ahmed');
        $this->assertNotNull($ahmed);
        $this->assertSame($hotel->name, $ahmed['hotel']['name']);
        $this->assertSame(5, $ahmed['rating']);
        $this->assertSame('ممتاز', $ahmed['text']);
        $this->assertCount(1, $ahmed['category_ratings']);
        $this->assertSame($category->id, $ahmed['category_ratings'][0]['category_id']);
        $this->assertSame('الراحة', $ahmed['category_ratings'][0]['name']);
        $this->assertSame(4, $ahmed['category_ratings'][0]['rating']);
    }

    public function test_a_rated_category_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $hotel = $this->hotel();
        $used = ReviewCategory::factory()->create(['hotel_id' => $hotel->id]);
        $unused = ReviewCategory::factory()->create(['hotel_id' => $hotel->id]);
        $this->submitAsGuest($this->completedReservation($hotel), [
            'rating' => 5, 'category_ratings' => [['category_id' => $used->id, 'rating' => 5]],
        ])->assertStatus(201);

        $manager = $this->managerFor($hotel);
        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/hotels/{$hotel->id}/review-categories/{$used->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'category_in_use');
        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/hotels/{$hotel->id}/review-categories/{$unused->id}")
            ->assertOk();

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
    }

    public function test_categories_are_reordered_only_with_the_hotels_full_set(): void
    {
        $hotel = $this->hotel();
        $a = ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'A', 'sort_order' => 0]);
        $b = ReviewCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'B', 'sort_order' => 1]);
        $foreign = ReviewCategory::factory()->create(['hotel_id' => $this->hotel()->id]);
        $manager = $this->managerFor($hotel);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/review-categories/reorder", ['ids' => [$b->id, $a->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $b->id);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/review-categories/reorder", ['ids' => [$b->id, $foreign->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'reorder_mismatch');
    }

    public function test_managing_categories_needs_the_permission_and_hotel_scope(): void
    {
        $hotel = $this->hotel();
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $otherManager = $this->managerFor($this->hotel());

        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/review-categories", ['name' => 'X'])
            ->assertStatus(403);
        $this->actingAs($otherManager, 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/review-categories", ['name' => 'X'])
            ->assertStatus(403);
    }
}
