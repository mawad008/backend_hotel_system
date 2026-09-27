<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Review\Models\Review;
use Tests\TestCase;

class GuestReviewTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function reservationFor(Guest $guest, string $status = Reservation::STATUS_INVOICED, ?Hotel $hotel = null): Reservation
    {
        $hotel ??= Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => $status, 'price_snapshot' => '200.00',
        ]);
    }

    public function test_unauthenticated_is_401(): void
    {
        $reservation = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/review")->assertStatus(401);
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", ['rating' => 5])->assertStatus(401);
    }

    public function test_guest_submits_a_review_for_a_completed_reservation(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", [
            'rating' => 4, 'text' => 'Great stay',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.status', Review::STATUS_PENDING)
            ->assertJsonMissingPath('data.hotel_id');

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/review")
            ->assertOk()
            ->assertJsonPath('data.rating', 4);
    }

    public function test_a_second_submit_returns_the_existing_review_not_a_duplicate(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", ['rating' => 5])
            ->assertStatus(201);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", ['rating' => 2])
            ->assertStatus(200)
            ->assertJsonPath('data.rating', 5);

        $this->assertSame(1, Review::query()->where('reservation_id', $reservation->id)->count());
    }

    public function test_guest_cannot_review_an_ineligible_reservation(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest, Reservation::STATUS_IN_STAY);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", ['rating' => 5])
            ->assertStatus(422);
    }

    public function test_guest_cannot_set_moderation_state_or_hotel_id(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);
        $otherHotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", [
            'rating' => 5, 'status' => Review::STATUS_PUBLISHED, 'hotel_id' => $otherHotel->id,
        ])->assertStatus(201);

        $review = Review::query()->where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame(Review::STATUS_PENDING, $review->status);
        $this->assertSame($reservation->hotel_id, $review->hotel_id);
    }

    public function test_rating_is_validated(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", ['rating' => 0])->assertStatus(422);
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", ['rating' => 6])->assertStatus(422);
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", [])->assertStatus(422);

        // Global TrimStrings + ConvertEmptyStringsToNull middleware coerces
        // a whitespace-only body to null before validation ever sees it —
        // "never whitespace-only" holds by construction, not via the
        // closure rule (which guards non-JSON/other input paths).
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/review", ['rating' => 5, 'text' => '   '])
            ->assertStatus(201)
            ->assertJsonPath('data.text', null);
    }

    public function test_guest_cannot_reach_another_guests_review(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create(['status' => Reservation::STATUS_INVOICED]);

        $this->getJson("/api/v1/guest/reservations/{$other->id}/review")->assertStatus(404);
        $this->postJson("/api/v1/guest/reservations/{$other->id}/review", ['rating' => 5])->assertStatus(404);
    }

    public function test_no_review_yet_is_404_not_an_empty_object(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/review")->assertStatus(404);
    }

    public function test_public_hotel_reviews_listing_returns_only_published(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        Review::factory()->published()->create(['hotel_id' => $hotel->id]);
        Review::factory()->rejected()->create(['hotel_id' => $hotel->id]);
        Review::factory()->create(['hotel_id' => $hotel->id]); // pending

        $response = $this->getJson("/api/v1/guest/hotels/{$hotel->id}/reviews")->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame(Review::STATUS_PUBLISHED, $response->json('data.0.status'));
    }

    public function test_public_hotel_reviews_listing_404s_for_missing_or_inactive_hotel(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id, 'is_active' => false]);

        $this->getJson('/api/v1/guest/hotels/999999/reviews')->assertStatus(404);
        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/reviews")->assertStatus(404);
    }
}
