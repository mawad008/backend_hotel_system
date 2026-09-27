<?php

namespace Tests\Unit\Checkout;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationExtensionService;
use App\Domain\StayServices\Models\FolioCharge;
use Carbon\CarbonImmutable;

/**
 * Diagnostic: does a stay extension before checkout end up counted twice —
 * once in the `stay_extension` folio charge ReservationExtensionService
 * posts immediately, and again in the `accommodation` folio charge
 * CheckoutService posts from `reservation.price_snapshot` (which
 * ReservationExtensionService also bumps by the same extension amount)?
 */
class CheckoutExtensionAccountingTest extends CheckoutTestCase
{
    public function test_a_single_extension_before_checkout_is_not_double_counted(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => '500.00']);
        \App\Domain\Inventory\Models\Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_IN_STAY,
            'check_in' => '2026-01-01',
            'check_out' => '2026-01-03',
            'price_snapshot' => '1000.00',
        ]);

        \App\Domain\Payment\Models\Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'amount' => '0.00',
        ]);
        $this->markPreviouslyCaptured($reservation, '1000.00');

        app(ReservationExtensionService::class)->extend(
            $reservation,
            CarbonImmutable::parse('2026-01-04'),
            actor: null,
        );

        $this->assertSame('1500.00', $reservation->fresh()->price_snapshot);
        $this->assertSame('500.00', FolioCharge::where('source_type', FolioCharge::SOURCE_STAY_EXTENSION)->sole()->total_amount);

        $result = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);

        $this->assertTrue($result->isComplete());
        // The guest owes 1000 (original 2 nights) + 500 (the 1 night added)
        // = 1500 — never 2000. The accommodation charge must reflect only
        // the ORIGINAL price, since the extension already has its own
        // separate, already-posted folio charge.
        $this->assertSame('1500.00', $result->invoice->subtotal);
        $this->assertSame(2, FolioCharge::where('status', FolioCharge::STATUS_POSTED)->count());
    }

    public function test_two_extensions_and_a_repeated_checkout_never_duplicate_or_drift(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => '500.00']);
        \App\Domain\Inventory\Models\Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_IN_STAY,
            'check_in' => '2026-01-01',
            'check_out' => '2026-01-03',
            'price_snapshot' => '1000.00',
        ]);

        \App\Domain\Payment\Models\Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'amount' => '0.00',
        ]);
        $this->markPreviouslyCaptured($reservation, '1000.00');

        $extensions = app(ReservationExtensionService::class);
        // 2026-01-03 -> 2026-01-04 (1 night, 500), then 01-04 -> 01-06 (2 nights, 1000).
        $extensions->extend($reservation, CarbonImmutable::parse('2026-01-04'), actor: null);
        $extensions->extend($reservation->fresh(), CarbonImmutable::parse('2026-01-06'), actor: null);

        $this->assertSame('2500.00', $reservation->fresh()->price_snapshot); // 1000 + 500 + 1000
        $this->assertSame(2, FolioCharge::where('source_type', FolioCharge::SOURCE_STAY_EXTENSION)->count());

        $first = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);
        $second = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);

        $this->assertTrue($first->isComplete() && $second->isComplete());
        // 1000 (original) + 500 + 1000 (both extensions) = 2500, exactly once.
        $this->assertSame('2500.00', $first->invoice->subtotal);
        $this->assertSame('2500.00', $second->invoice->subtotal);
        $this->assertSame($first->invoice->id, $second->invoice->id);
        $this->assertSame(1, \App\Domain\Checkout\Models\Invoice::count());
        $this->assertSame(1, FolioCharge::where('source_type', FolioCharge::SOURCE_ACCOMMODATION)->count());
        $this->assertSame('1000.00', FolioCharge::where('source_type', FolioCharge::SOURCE_ACCOMMODATION)->sole()->total_amount);
    }
}
