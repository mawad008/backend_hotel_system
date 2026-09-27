<?php

namespace Tests\Unit\Checkout;

use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceOrder;
use Tests\TestCase;

abstract class CheckoutTestCase extends TestCase
{
    /**
     * A reservation ready for checkout: IN_STAY with an active deposit hold.
     *
     * `accommodation` defaults to '0.00' so lifecycle/settlement tests stay
     * isolated from the accommodation charge; pass a value to exercise it.
     * `depositAmount` is the held deposit — recorded on the Payment row (the
     * Phase 5 meaning), never counted as collected money on its own.
     */
    protected function inStayReservation(
        ?Hotel $hotel = null,
        string $depositAmount = '0.00',
        string $accommodation = '0.00',
    ): Reservation {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_IN_STAY,
            'price_snapshot' => $accommodation,
        ]);

        Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $hotel->id,
            'amount' => $depositAmount,
            'currency' => 'USD',
        ]);

        return $reservation;
    }

    /**
     * Represent an amount already collected from the guest before checkout
     * (a capture-at-check-in that a future phase will perform): the Payment
     * moves to CAPTURED and a SUCCEEDED `capture` PaymentTransaction records
     * the amount. `payments_total` is derived from that transaction, not
     * from `payments.amount`.
     */
    protected function markPreviouslyCaptured(Reservation $reservation, string $amount): PaymentTransaction
    {
        $payment = $reservation->payment;
        $payment->update(['status' => Payment::STATUS_CAPTURED]);

        return PaymentTransaction::factory()->create([
            'payment_id' => $payment->id,
            'type' => PaymentTransaction::TYPE_CAPTURE,
            'status' => PaymentTransaction::STATUS_SUCCEEDED,
            'amount' => $amount,
        ]);
    }

    /**
     * Add a posted folio charge (a confirmed service order's charge) to a
     * reservation.
     */
    protected function postCharge(Reservation $reservation, string $unit, int $qty = 1): FolioCharge
    {
        $service = HotelService::factory()->create([
            'hotel_id' => $reservation->hotel_id,
            'price' => $unit,
            'currency' => 'USD',
        ]);

        $order = ServiceOrder::factory()->confirmed()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'service_id' => $service->id,
            'quantity' => $qty,
            'unit_price_snapshot' => $unit,
            'currency_snapshot' => 'USD',
            'total_amount' => bcmul($unit, (string) $qty, 2),
        ]);

        return FolioCharge::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'source_type' => FolioCharge::SOURCE_SERVICE_ORDER,
            'source_id' => $order->id,
            'description' => 'Service x'.$qty,
            'quantity' => $qty,
            'unit_amount' => $unit,
            'total_amount' => bcmul($unit, (string) $qty, 2),
            'currency' => 'USD',
            'status' => FolioCharge::STATUS_POSTED,
        ]);
    }

    protected function checkoutService(?PaymentGatewayInterface $gateway = null): CheckoutService
    {
        if ($gateway !== null) {
            $this->app->instance(PaymentGatewayInterface::class, $gateway);
        }

        return $this->app->make(CheckoutService::class);
    }
}
