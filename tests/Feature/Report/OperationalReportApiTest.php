<?php

namespace Tests\Feature\Report;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Review\Models\Review;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceOrder;
use Tests\TestCase;

class OperationalReportApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    private function reservation(Hotel $hotel, array $attrs = []): Reservation
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create(array_merge([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
        ], $attrs));
    }

    public function test_reservations_report_counts_by_status_within_the_range(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $this->reservation($hotel, ['status' => Reservation::STATUS_VERIFIED, 'check_in' => '2026-02-05']);
        $this->reservation($hotel, ['status' => Reservation::STATUS_VERIFIED, 'check_in' => '2026-02-06']);
        $this->reservation($hotel, ['status' => Reservation::STATUS_CANCELLED, 'check_in' => '2026-02-06']);
        $this->reservation($hotel, ['status' => Reservation::STATUS_VERIFIED, 'check_in' => '2026-03-01']); // out of range

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reports/reservations?from=2026-02-01&to=2026-02-28&hotel_id={$hotel->id}")
            ->assertOk();

        $response->assertJsonPath('data.hotels.0.by_status.verified', 2)
            ->assertJsonPath('data.hotels.0.by_status.cancelled', 1)
            ->assertJsonPath('data.hotels.0.total', 3)
            ->assertJsonPath('data.totals.verified', 2);
    }

    public function test_payments_report_counts_by_status_within_the_range(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $r1 = $this->reservation($hotel);
        $r2 = $this->reservation($hotel);
        Payment::factory()->create(['reservation_id' => $r1->id, 'hotel_id' => $hotel->id, 'status' => Payment::STATUS_HOLD_ACTIVE]);
        Payment::factory()->captured()->create(['reservation_id' => $r2->id, 'hotel_id' => $hotel->id]);

        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reports/payments?from={$from}&to={$to}&hotel_id={$hotel->id}")
            ->assertOk();

        $response->assertJsonPath('data.hotels.0.by_status.hold_active', 1)
            ->assertJsonPath('data.hotels.0.by_status.captured', 1)
            ->assertJsonPath('data.hotels.0.total', 2);
    }

    public function test_services_report_counts_and_sums_billable_revenue(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $r1 = $this->reservation($hotel);
        $r2 = $this->reservation($hotel);
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id, 'price' => '25.00', 'currency' => 'USD']);

        ServiceOrder::factory()->create([
            'reservation_id' => $r1->id, 'hotel_id' => $hotel->id, 'service_id' => $service->id,
            'status' => ServiceOrder::STATUS_CONFIRMED, 'quantity' => 1,
            'unit_price_snapshot' => '25.00', 'currency_snapshot' => 'USD', 'total_amount' => '25.00',
        ]);
        ServiceOrder::factory()->create([
            'reservation_id' => $r2->id, 'hotel_id' => $hotel->id, 'service_id' => $service->id,
            'status' => ServiceOrder::STATUS_REQUESTED, 'quantity' => 1,
            'unit_price_snapshot' => '25.00', 'currency_snapshot' => 'USD', 'total_amount' => '25.00',
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reports/services?from={$from}&to={$to}&hotel_id={$hotel->id}")
            ->assertOk();

        $response->assertJsonPath('data.hotels.0.by_status.confirmed', 1)
            ->assertJsonPath('data.hotels.0.by_status.requested', 1)
            ->assertJsonPath('data.hotels.0.total', 2)
            ->assertJsonPath('data.hotels.0.revenue.0.currency', 'USD')
            ->assertJsonPath('data.hotels.0.revenue.0.amount', '25.00');
    }

    public function test_loyalty_report_sums_earn_and_redeem_scoped_to_the_hotel(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);
        $guest = Guest::factory()->create();
        $account = LoyaltyAccount::factory()->create(['guest_id' => $guest->id]);

        $reservationA = $this->reservation($hotelA, ['guest_id' => $guest->id]);
        $reservationB = $this->reservation($hotelB, ['guest_id' => $guest->id]);

        LoyaltyTransaction::factory()->earn(100)->forReservation($reservationA->id)
            ->create(['loyalty_account_id' => $account->id]);
        LoyaltyTransaction::factory()->redeem(40)->forReservation($reservationA->id)
            ->create(['loyalty_account_id' => $account->id]);
        // Different hotel — must never appear in hotel A's report.
        LoyaltyTransaction::factory()->earn(500)->forReservation($reservationB->id)
            ->create(['loyalty_account_id' => $account->id]);

        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reports/loyalty?from={$from}&to={$to}&hotel_id={$hotelA->id}")
            ->assertOk();

        $response->assertJsonPath('data.hotels.0.points_earned', 100)
            ->assertJsonPath('data.hotels.0.points_redeemed', 40)
            ->assertJsonPath('data.hotels.0.net', 60);
    }

    public function test_reviews_report_counts_by_status_and_averages_rating(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        Review::factory()->published()->create(['hotel_id' => $hotel->id, 'rating' => 4]);
        Review::factory()->published()->create(['hotel_id' => $hotel->id, 'rating' => 2]);
        Review::factory()->create(['hotel_id' => $hotel->id, 'rating' => 5]); // pending

        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reports/reviews?from={$from}&to={$to}&hotel_id={$hotel->id}")
            ->assertOk();

        $response->assertJsonPath('data.hotels.0.by_status.published', 2)
            ->assertJsonPath('data.hotels.0.by_status.pending', 1)
            ->assertJsonPath('data.hotels.0.total', 3)
            ->assertJsonPath('data.hotels.0.average_rating', 3.67);
    }

    public function test_reception_cannot_view_operational_reports(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->getJson('/api/v1/reports/loyalty?from=2026-01-01&to=2026-01-08')
            ->assertStatus(403);
    }
}
