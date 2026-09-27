<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Models\ServiceReview;
use Tests\TestCase;

class GuestServiceReviewTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    /**
     * @return array{0: Hotel, 1: Reservation}
     */
    private function reservationFor(Guest $guest, string $status = Reservation::STATUS_CHECKED_IN): array
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => $status,
        ]);

        return [$hotel, $reservation];
    }

    private function orderFor(Hotel $hotel, Reservation $reservation, string $status): ServiceOrder
    {
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);

        return ServiceOrder::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $hotel->id,
            'service_id' => $service->id,
            'status' => $status,
        ]);
    }

    public function test_unauthenticated_is_401(): void
    {
        $order = ServiceOrder::factory()->fulfilled()->create();

        $this->getJson("/api/v1/guest/reservations/{$order->reservation_id}/service-orders/{$order->id}/review")
            ->assertStatus(401);
    }

    public function test_no_review_yet_is_404(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $order = $this->orderFor($hotel, $reservation, ServiceOrder::STATUS_FULFILLED);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/review")
            ->assertStatus(404);
    }

    public function test_guest_submits_a_review_for_a_fulfilled_order(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $order = $this->orderFor($hotel, $reservation, ServiceOrder::STATUS_FULFILLED);

        $this->postJson(
            "/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/review",
            ['rating' => 5, 'text' => 'Excellent room service'],
        )->assertStatus(201)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.text', 'Excellent room service')
            ->assertJsonPath('data.status', ServiceReview::STATUS_PENDING)
            ->assertJsonMissingPath('data.hotel_id')
            ->assertJsonMissingPath('data.guest_id');

        $this->assertDatabaseHas('service_reviews', [
            'service_order_id' => $order->id,
            'guest_id' => $guest->id,
            'hotel_id' => $hotel->id,
            'service_id' => $order->service_id,
            'rating' => 5,
        ]);
    }

    public function test_a_non_fulfilled_order_cannot_be_reviewed(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $order = $this->orderFor($hotel, $reservation, ServiceOrder::STATUS_REQUESTED);

        $this->postJson(
            "/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/review",
            ['rating' => 4],
        )->assertStatus(422);

        $this->assertDatabaseMissing('service_reviews', ['service_order_id' => $order->id]);
    }

    public function test_submitting_twice_for_the_same_order_is_idempotent_not_a_duplicate(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $order = $this->orderFor($hotel, $reservation, ServiceOrder::STATUS_FULFILLED);

        $this->postJson(
            "/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/review",
            ['rating' => 5],
        )->assertStatus(201);

        $this->postJson(
            "/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/review",
            ['rating' => 2],
        )->assertStatus(200)
            // The second submit's rating is discarded — the first review wins.
            ->assertJsonPath('data.rating', 5);

        $this->assertSame(1, ServiceReview::query()->where('service_order_id', $order->id)->count());
    }

    public function test_guest_cannot_review_another_guests_service_order(): void
    {
        $this->actingGuest();
        $otherOrder = ServiceOrder::factory()->fulfilled()->create();

        $this->getJson(
            "/api/v1/guest/reservations/{$otherOrder->reservation_id}/service-orders/{$otherOrder->id}/review",
        )->assertStatus(404);

        $this->postJson(
            "/api/v1/guest/reservations/{$otherOrder->reservation_id}/service-orders/{$otherOrder->id}/review",
            ['rating' => 5],
        )->assertStatus(404);

        $this->assertDatabaseMissing('service_reviews', ['service_order_id' => $otherOrder->id]);
    }

    public function test_an_order_cannot_be_reviewed_through_someone_elses_reservation(): void
    {
        $guest = $this->actingGuest();
        [, $reservation] = $this->reservationFor($guest);
        // A fulfilled order that belongs to a DIFFERENT reservation entirely.
        $foreignOrder = ServiceOrder::factory()->fulfilled()->create();

        $this->postJson(
            "/api/v1/guest/reservations/{$reservation->id}/service-orders/{$foreignOrder->id}/review",
            ['rating' => 5],
        )->assertStatus(404);
    }

    public function test_rating_must_be_between_1_and_5(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $order = $this->orderFor($hotel, $reservation, ServiceOrder::STATUS_FULFILLED);

        $this->postJson(
            "/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/review",
            ['rating' => 6],
        )->assertStatus(422);
    }
}
