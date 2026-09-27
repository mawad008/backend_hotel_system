<?php

namespace Tests\Unit\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\HotelService;
use Tests\TestCase;

abstract class StayServicesTestCase extends TestCase
{
    /**
     * A reservation in a status during which services may be consumed.
     */
    protected function serviceableReservation(?Hotel $hotel = null, string $status = Reservation::STATUS_CHECKED_IN): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => $status,
        ]);
    }

    protected function service(Hotel $hotel, string $price = '25.00', bool $active = true, ?string $currency = 'USD'): HotelService
    {
        return HotelService::factory()->create([
            'hotel_id' => $hotel->id,
            'price' => $price,
            'currency' => $currency,
            'is_active' => $active,
        ]);
    }

    /**
     * A payment that has actually collected $amount from the guest: the
     * Payment row plus a SUCCEEDED collecting PaymentTransaction (a `capture`
     * for a still-held deposit, a `settlement` once fully settled). Since the
     * Phase 9 review, `payments_total` is derived from the transaction
     * history, not `payments.amount`.
     */
    protected function capturedPayment(Reservation $reservation, string $amount, string $status = Payment::STATUS_CAPTURED): Payment
    {
        $payment = Payment::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'USD',
        ]);

        if (in_array($status, [Payment::STATUS_CAPTURED, Payment::STATUS_SETTLED], true)) {
            PaymentTransaction::factory()->create([
                'payment_id' => $payment->id,
                'type' => $status === Payment::STATUS_SETTLED
                    ? PaymentTransaction::TYPE_SETTLEMENT
                    : PaymentTransaction::TYPE_CAPTURE,
                'status' => PaymentTransaction::STATUS_SUCCEEDED,
                'amount' => $amount,
            ]);
        }

        return $payment;
    }
}
