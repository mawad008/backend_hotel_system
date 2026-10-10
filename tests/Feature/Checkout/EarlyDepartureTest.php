<?php

namespace Tests\Feature\Checkout;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A guest may check out on any day of the stay; checkout then bills, reports
 * and frees the room by the nights actually stayed
 * (CheckoutService::recordDeparture).
 *
 * Fixture: one room, 100.00/night, check-in 2026-10-07, booked check-out
 * 2026-10-10 (3 nights, 300.00), hotel in UTC.
 */
class EarlyDepartureTest extends TestCase
{
    private Hotel $hotel;

    private RoomType $roomType;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');

        $this->hotel = Hotel::factory()->create([
            'hotel_group_id' => HotelGroup::factory()->create()->id,
            'timezone' => 'UTC',
        ]);
        $this->roomType = RoomType::factory()->create(['hotel_id' => $this->hotel->id, 'base_price' => '100.00', 'capacity' => 2]);
        Room::factory()->create(['hotel_id' => $this->hotel->id, 'room_type_id' => $this->roomType->id]);

        $this->guest = Guest::factory()->create();
        $this->withToken($this->guest->createToken('guest-api')->plainTextToken);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stay(string $serviceFee = '0.00', string $checkIn = '2026-10-07', string $checkOut = '2026-10-10', string $price = '300.00'): Reservation
    {
        $room = Room::query()->where('room_type_id', $this->roomType->id)->firstOrFail();
        $reservation = Reservation::factory()->create([
            'guest_id' => $this->guest->id, 'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id, 'room_id' => $room->id,
            'status' => Reservation::STATUS_IN_STAY,
            'check_in' => $checkIn, 'check_out' => $checkOut,
            'price_snapshot' => $price, 'service_fee_amount' => $serviceFee, 'currency' => 'USD',
        ]);
        Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $this->hotel->id, 'amount' => '0.00', 'currency' => 'USD',
        ]);

        return $reservation;
    }

    private function checkout(Reservation $reservation): TestResponse
    {
        return $this->postJson(
            "/api/v1/guest/reservations/{$reservation->id}/checkout",
            [],
            ['X-Payment-Simulate' => 'success'],
        );
    }

    private function postedCharge(Reservation $reservation, string $source): ?FolioCharge
    {
        return FolioCharge::query()
            ->where('reservation_id', $reservation->id)
            ->where('source_type', $source)
            ->where('status', FolioCharge::STATUS_POSTED)
            ->first();
    }

    // ── Core rule ─────────────────────────────────────────────────

    public function test_three_booked_nights_leaving_after_one_bills_one_night(): void
    {
        $reservation = $this->stay();

        $this->checkout($reservation)
            ->assertOk()
            ->assertJsonPath('data.checkout.status', Checkout::STATUS_COMPLETED)
            ->assertJsonPath('data.reservation.status', Reservation::STATUS_INVOICED)
            ->assertJsonPath('data.totals.charges_total', '100.00');

        $reservation->refresh();
        $this->assertSame('2026-10-08', $reservation->check_out->toDateString());
        $this->assertSame('100.00', (string) $reservation->price_snapshot);
        $this->assertSame('2026-10-08 10:00:00', $reservation->checked_out_at->toDateTimeString());
    }

    public function test_the_original_check_out_date_is_preserved(): void
    {
        $reservation = $this->stay();
        $this->checkout($reservation)->assertOk();

        $this->assertSame('2026-10-10', $reservation->fresh()->original_check_out->toDateString());
        $this->getJson("/api/v1/guest/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.check_out', '2026-10-08')
            ->assertJsonPath('data.nights', 1)
            ->assertJsonPath('data.original_check_out', '2026-10-10');
    }

    public function test_the_original_price_snapshot_is_preserved(): void
    {
        $reservation = $this->stay();
        $this->checkout($reservation)->assertOk();

        $this->assertSame('300.00', (string) $reservation->fresh()->original_price_snapshot);
    }

    public function test_an_audit_log_records_the_early_departure(): void
    {
        $reservation = $this->stay();
        $this->checkout($reservation)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.departed_early',
            'auditable_type' => $reservation->getMorphClass(),
            'auditable_id' => $reservation->id,
        ]);
    }

    public function test_checking_out_on_the_booked_date_keeps_the_full_stay(): void
    {
        $reservation = $this->stay();
        Carbon::setTestNow('2026-10-10 11:00:00');

        $this->checkout($reservation)
            ->assertOk()
            ->assertJsonPath('data.totals.charges_total', '300.00');

        $reservation->refresh();
        $this->assertSame('2026-10-10', $reservation->check_out->toDateString());
        $this->assertSame('300.00', (string) $reservation->price_snapshot);
        $this->assertNull($reservation->original_check_out);
        $this->assertNull($reservation->original_price_snapshot);
        $this->assertNotNull($reservation->checked_out_at);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'reservation.departed_early']);
    }

    public function test_checking_out_after_the_booked_date_never_bills_more_than_booked(): void
    {
        $reservation = $this->stay();
        Carbon::setTestNow('2026-10-11 09:00:00');

        $this->checkout($reservation)->assertOk()->assertJsonPath('data.totals.charges_total', '300.00');
        $this->assertSame('2026-10-10', $reservation->fresh()->check_out->toDateString());
    }

    public function test_leaving_on_the_check_in_day_still_bills_the_one_night_minimum(): void
    {
        $reservation = $this->stay();
        Carbon::setTestNow('2026-10-07 22:00:00');

        $this->checkout($reservation)->assertOk()->assertJsonPath('data.totals.charges_total', '100.00');

        $reservation->refresh();
        $this->assertSame('2026-10-08', $reservation->check_out->toDateString());
        $this->assertSame('100.00', (string) $reservation->price_snapshot);
    }

    public function test_departure_day_is_the_hotels_local_date(): void
    {
        $this->hotel->update(['timezone' => 'Asia/Riyadh']); // UTC+3
        $reservation = $this->stay();
        // 22:30 UTC on the 8th is already the 9th in Riyadh → 2 nights.
        Carbon::setTestNow('2026-10-08 22:30:00');

        $this->checkout($reservation)->assertOk()->assertJsonPath('data.totals.charges_total', '200.00');
        $this->assertSame('2026-10-09', $reservation->fresh()->check_out->toDateString());
    }

    // ── Money ─────────────────────────────────────────────────────

    public function test_the_service_fee_stays_per_booking(): void
    {
        $reservation = $this->stay(serviceFee: '50.00');

        $this->checkout($reservation)->assertOk()->assertJsonPath('data.totals.charges_total', '150.00');

        $this->assertSame('50.00', (string) $reservation->fresh()->service_fee_amount);
        $this->assertSame('50.00', (string) $this->postedCharge($reservation, FolioCharge::SOURCE_SERVICE_FEE)->total_amount);
        $this->assertSame('100.00', (string) $this->postedCharge($reservation, FolioCharge::SOURCE_ACCOMMODATION)->total_amount);
    }

    public function test_the_tax_is_charged_on_the_actual_stay(): void
    {
        $reservation = $this->stay(serviceFee: '50.00');
        $reservation->update(['tax_rate' => '10.00']);

        // (100.00 stayed + 50.00 fee) × 10% = 15.00.
        $this->checkout($reservation)->assertOk()->assertJsonPath('data.totals.charges_total', '165.00');

        $this->assertSame('15.00', (string) $this->postedCharge($reservation, FolioCharge::SOURCE_TAX)->total_amount);
    }

    public function test_the_invoice_bills_the_actual_stay(): void
    {
        $reservation = $this->stay();
        $this->checkout($reservation)->assertOk();

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/invoice")
            ->assertOk()
            ->assertJsonPath('data.subtotal', '100.00');

        $this->assertDatabaseHas('invoice_items', [
            'source_type' => FolioCharge::SOURCE_ACCOMMODATION,
            'total_amount' => '100.00',
        ]);
        $this->assertDatabaseMissing('invoice_items', ['total_amount' => '300.00']);
    }

    public function test_the_remaining_payment_collects_only_the_actual_stay(): void
    {
        $reservation = $this->stay();

        $this->checkout($reservation)
            ->assertOk()
            ->assertJsonPath('data.payment.status', Payment::STATUS_SETTLED)
            ->assertJsonPath('data.totals.outstanding_total', '0.00')
            ->assertJsonPath('data.totals.payments_total', '100.00');

        $this->assertSame(
            '100.00',
            (string) PaymentTransaction::query()
                ->where('type', PaymentTransaction::TYPE_SETTLEMENT)
                ->where('status', PaymentTransaction::STATUS_SUCCEEDED)
                ->sole()
                ->amount,
        );
    }

    // ── Reports, inventory, loyalty ───────────────────────────────

    public function test_occupancy_counts_only_the_nights_actually_stayed(): void
    {
        $reservation = $this->stay();
        $this->checkout($reservation)->assertOk();

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($this->hotel);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reports/occupancy?from=2026-10-07&to=2026-10-10&hotel_id={$this->hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.hotels.0.booked_room_nights', 1);
    }

    public function test_the_room_is_available_again_after_the_actual_departure(): void
    {
        $reservation = $this->stay();
        $url = "/api/v1/guest/hotels/{$this->hotel->id}/availability?check_in=2026-10-08&check_out=2026-10-10&adults=1";

        $this->getJson($url)->assertOk()->assertJsonPath('data.rooms.0.rooms_available', 0);

        $this->checkout($reservation)->assertOk();

        $this->getJson($url)->assertOk()->assertJsonPath('data.rooms.0.rooms_available', 1);
    }

    public function test_loyalty_points_are_earned_on_the_actual_stay(): void
    {
        // Existing rule: 1 point per currency unit of the completed stay's price_snapshot.
        LoyaltyRule::factory()->active(earnRate: '1.0000')->create(['hotel_group_id' => $this->hotel->hotel_group_id]);
        $reservation = $this->stay();

        $this->checkout($reservation)->assertOk();

        $earn = LoyaltyTransaction::query()
            ->where('type', LoyaltyTransaction::TYPE_EARN)
            ->where('source_id', $reservation->id)
            ->sole();
        $this->assertSame(100, (int) $earn->points);
    }

    // ── Extend Stay reconciliation (regression) ───────────────────

    /** Booked 2 nights @100 (to 10-09), extended 3 nights @150 (to 10-12). */
    private function extendedStay(): Reservation
    {
        $reservation = $this->stay(checkOut: '2026-10-09', price: '200.00');
        $this->roomType->update(['base_price' => '150.00']);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/extend", ['new_check_out' => '2026-10-12'])
            ->assertOk()
            ->assertJsonPath('data.extension.amount', '450.00');

        $this->assertSame('650.00', (string) $reservation->fresh()->price_snapshot);

        return $reservation;
    }

    public function test_extended_stay_left_midway_through_the_extension_bills_each_night_at_its_own_rate(): void
    {
        $reservation = $this->extendedStay();
        Carbon::setTestNow('2026-10-10 09:00:00'); // 2 booked nights + 1 extension night

        $this->checkout($reservation)
            ->assertOk()
            ->assertJsonPath('data.checkout.status', Checkout::STATUS_COMPLETED)
            ->assertJsonPath('data.totals.charges_total', '350.00')
            ->assertJsonPath('data.totals.outstanding_total', '0.00');

        $reservation->refresh();
        $this->assertSame('2026-10-10', $reservation->check_out->toDateString());
        $this->assertSame('350.00', (string) $reservation->price_snapshot);
        $this->assertSame('2026-10-12', $reservation->original_check_out->toDateString());
        $this->assertSame('650.00', (string) $reservation->original_price_snapshot);

        $extensionCharge = $this->postedCharge($reservation, FolioCharge::SOURCE_STAY_EXTENSION);
        $this->assertSame(1, $extensionCharge->quantity);
        $this->assertSame('150.00', (string) $extensionCharge->total_amount);
        $this->assertSame('200.00', (string) $this->postedCharge($reservation, FolioCharge::SOURCE_ACCOMMODATION)->total_amount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'folio_charge.adjusted', 'auditable_id' => $extensionCharge->id]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/invoice")
            ->assertOk()
            ->assertJsonPath('data.subtotal', '350.00');
    }

    public function test_extended_stay_left_before_the_original_date_never_bills_the_extension(): void
    {
        $reservation = $this->extendedStay();
        // Still 10-08: 1 night of the original booking, none of the extension.

        $this->checkout($reservation)
            ->assertOk()
            ->assertJsonPath('data.totals.charges_total', '100.00')
            ->assertJsonPath('data.totals.payments_total', '100.00');

        $this->assertSame('100.00', (string) $reservation->fresh()->price_snapshot);
        $this->assertNull($this->postedCharge($reservation, FolioCharge::SOURCE_STAY_EXTENSION));
        $this->assertDatabaseHas('folio_charges', [
            'reservation_id' => $reservation->id,
            'source_type' => FolioCharge::SOURCE_STAY_EXTENSION,
            'status' => FolioCharge::STATUS_CANCELLED,
        ]);
        $this->assertSame('100.00', (string) $this->postedCharge($reservation, FolioCharge::SOURCE_ACCOMMODATION)->total_amount);
    }

    public function test_extended_stay_that_runs_its_full_length_is_billed_in_full(): void
    {
        $reservation = $this->extendedStay();
        Carbon::setTestNow('2026-10-12 10:00:00');

        $this->checkout($reservation)->assertOk()->assertJsonPath('data.totals.charges_total', '650.00');

        $this->assertSame('450.00', (string) $this->postedCharge($reservation, FolioCharge::SOURCE_STAY_EXTENSION)->total_amount);
        $this->assertNull($reservation->fresh()->original_check_out);
    }
}
