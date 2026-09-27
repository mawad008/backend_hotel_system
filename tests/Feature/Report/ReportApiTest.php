<?php

namespace Tests\Feature\Report;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    public function test_occupancy_report_computes_room_nights_clamped_to_the_range(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        // Fully inside the 10-night window: 4 nights.
        Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_IN_STAY,
            'check_in' => '2026-01-02', 'check_out' => '2026-01-06',
        ]);
        // Starts before the window, ends inside it: clamps to 2 nights (01-01..01-03).
        Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_CHECKED_OUT,
            'check_in' => '2025-12-30', 'check_out' => '2026-01-03',
        ]);
        // Cancelled — never counted.
        Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_CANCELLED,
            'check_in' => '2026-01-02', 'check_out' => '2026-01-06',
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reports/occupancy?from=2026-01-01&to=2026-01-11&hotel_id={$hotel->id}")
            ->assertOk();

        $response->assertJsonPath('data.hotels.0.rooms', 2)
            ->assertJsonPath('data.hotels.0.nights', 10)
            ->assertJsonPath('data.hotels.0.capacity_room_nights', 20)
            ->assertJsonPath('data.hotels.0.booked_room_nights', 6)
            ->assertJsonPath('data.hotels.0.occupancy_rate', 30);
    }

    public function test_revenue_report_sums_captured_payments_by_currency(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservationA = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $reservationB = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $reservationC = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        $captured = Payment::factory()->captured()->create([
            'reservation_id' => $reservationA->id, 'hotel_id' => $hotel->id,
            'currency' => 'USD', 'amount' => '100.00',
        ]);
        PaymentTransaction::factory()->succeeded()->create([
            'payment_id' => $captured->id, 'type' => PaymentTransaction::TYPE_CAPTURE,
            'amount' => '100.00', 'currency' => 'USD',
        ]);
        // `payments.amount` is the deposit hold (20.00); checkout settled the
        // whole folio (50.50) on a settlement transaction — that is revenue.
        $settled = Payment::factory()->settled()->create([
            'reservation_id' => $reservationB->id, 'hotel_id' => $hotel->id,
            'currency' => 'USD', 'amount' => '20.00',
        ]);
        PaymentTransaction::factory()->succeeded()->create([
            'payment_id' => $settled->id, 'type' => PaymentTransaction::TYPE_HOLD,
            'amount' => null, 'currency' => 'USD',
        ]);
        PaymentTransaction::factory()->succeeded()->settlement()->create([
            'payment_id' => $settled->id, 'amount' => '50.50', 'currency' => 'USD',
        ]);
        // A failed settlement attempt never counts.
        PaymentTransaction::factory()->failed()->settlement()->create([
            'payment_id' => $settled->id, 'amount' => '50.50', 'currency' => 'USD',
        ]);
        // Not captured — excluded.
        $held = Payment::factory()->create([
            'reservation_id' => $reservationC->id, 'hotel_id' => $hotel->id,
            'status' => Payment::STATUS_HOLD_ACTIVE, 'currency' => 'USD', 'amount' => '999.00',
        ]);
        PaymentTransaction::factory()->succeeded()->create([
            'payment_id' => $held->id, 'type' => PaymentTransaction::TYPE_HOLD,
            'amount' => '999.00', 'currency' => 'USD',
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reports/revenue?from={$from}&to={$to}&hotel_id={$hotel->id}")
            ->assertOk();

        $response->assertJsonPath('data.hotels.0.revenue.0.currency', 'USD')
            ->assertJsonPath('data.hotels.0.revenue.0.amount', '150.50')
            ->assertJsonPath('data.totals.0.amount', '150.50');
    }

    public function test_hotel_comparison_returns_every_accessible_hotel(): void
    {
        $group = HotelGroup::factory()->create();
        $owner = User::factory()->groupOwner()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        Room::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelA->id])->id]);
        Room::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reports/hotel-comparison?from=2026-01-01&to=2026-01-08')
            ->assertOk();

        $hotelIds = collect($response->json('data.hotels'))->pluck('hotel_id')->sort()->values()->all();
        $this->assertSame([$hotelA->id, $hotelB->id], $hotelIds);
    }

    public function test_an_inaccessible_hotel_id_yields_an_empty_result_not_an_error(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reports/occupancy?from=2026-01-01&to=2026-01-08&hotel_id={$hotelB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.hotels');
    }

    public function test_reception_cannot_view_reports(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->getJson('/api/v1/reports/occupancy?from=2026-01-01&to=2026-01-08')
            ->assertStatus(403);
    }

    public function test_range_is_required(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reports/occupancy')
            ->assertStatus(422);
    }

    public function test_reports_require_authentication(): void
    {
        $this->getJson('/api/v1/reports/occupancy?from=2026-01-01&to=2026-01-08')->assertStatus(401);
    }
}
