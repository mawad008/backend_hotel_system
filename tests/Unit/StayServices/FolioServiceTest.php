<?php

namespace Tests\Unit\StayServices;

use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Services\FolioService;

class FolioServiceTest extends StayServicesTestCase
{
    private function folio(): FolioService
    {
        return app(FolioService::class);
    }

    private function charge(Reservation $r, string $unit, int $qty, string $status = FolioCharge::STATUS_POSTED): FolioCharge
    {
        return FolioCharge::factory()->amount($unit, $qty)->create([
            'reservation_id' => $r->id,
            'hotel_id' => $r->hotel_id,
            'status' => $status,
            'source_id' => fake()->unique()->numberBetween(1, 9_999_999),
        ]);
    }

    public function test_zero_charges_and_zero_payments(): void
    {
        $folio = $this->folio()->folioFor($this->serviceableReservation());

        $this->assertSame('0.00', $folio->chargesTotal);
        $this->assertSame('0.00', $folio->paymentsTotal);
        $this->assertSame('0.00', $folio->outstandingTotal);
        $this->assertNull($folio->payment);
    }

    public function test_multiple_charges_sum_with_exact_decimals(): void
    {
        $r = $this->serviceableReservation();
        $this->charge($r, '10.10', 3);   // 30.30
        $this->charge($r, '0.05', 7);    // 0.35
        $this->charge($r, '99.99', 1);   // 99.99

        $folio = $this->folio()->folioFor($r);

        $this->assertSame('130.64', $folio->chargesTotal);
        $this->assertSame('130.64', $folio->outstandingTotal);
    }

    public function test_cancelled_charges_are_excluded_from_the_total(): void
    {
        $r = $this->serviceableReservation();
        $this->charge($r, '50.00', 1);
        $this->charge($r, '25.00', 2, FolioCharge::STATUS_CANCELLED);

        $folio = $this->folio()->folioFor($r);

        $this->assertSame('50.00', $folio->chargesTotal);
        $this->assertCount(2, $folio->charges, 'the resource still lists every charge');
    }

    public function test_captured_payment_counts_toward_payments_total(): void
    {
        $r = $this->serviceableReservation();
        $this->charge($r, '80.00', 1);
        $this->capturedPayment($r, '30.00', Payment::STATUS_CAPTURED);

        $folio = $this->folio()->folioFor($r);

        $this->assertSame('80.00', $folio->chargesTotal);
        $this->assertSame('30.00', $folio->paymentsTotal);
        $this->assertSame('50.00', $folio->outstandingTotal);
        $this->assertTrue($folio->isPaymentCaptured());
    }

    public function test_settled_payment_also_counts(): void
    {
        $r = $this->serviceableReservation();
        $this->capturedPayment($r, '45.00', Payment::STATUS_SETTLED);

        $this->assertSame('45.00', $this->folio()->folioFor($r)->paymentsTotal);
    }

    /**
     * A deposit hold, a pending capture and a failed payment are NOT money in.
     */
    public function test_non_captured_payment_statuses_contribute_zero(): void
    {
        foreach ([
            Payment::STATUS_HOLD_ACTIVE,
            Payment::STATUS_CAPTURE_REQUESTED,
            Payment::STATUS_CAPTURE_FAILED,
            Payment::STATUS_HOLD_FAILED,
            Payment::STATUS_FINAL_SETTLEMENT_REQUESTED,
        ] as $status) {
            $r = $this->serviceableReservation();
            $this->capturedPayment($r, '100.00', $status);

            $folio = $this->folio()->folioFor($r);
            $this->assertSame('0.00', $folio->paymentsTotal, "status {$status} must not count as money in");
            $this->assertFalse($folio->isPaymentCaptured());
        }
    }

    public function test_outstanding_can_be_negative_when_captured_exceeds_charges(): void
    {
        $r = $this->serviceableReservation();
        $this->charge($r, '20.00', 1);
        $this->capturedPayment($r, '50.00', Payment::STATUS_CAPTURED);

        $this->assertSame('-30.00', $this->folio()->folioFor($r)->outstandingTotal);
    }

    public function test_currency_is_resolved_but_never_invented(): void
    {
        // The reservation's own snapshot wins.
        $r = $this->serviceableReservation();
        $this->charge($r, '10.00', 1);
        $this->assertSame($r->currency, $this->folio()->folioFor($r)->currency);

        // Legacy reservation without a snapshot: falls back to the charge.
        $legacy = $this->serviceableReservation();
        $legacy->forceFill(['currency' => null])->saveQuietly();
        $this->charge($legacy, '10.00', 1);
        $this->assertSame('USD', $this->folio()->folioFor($legacy->fresh())->currency);

        $r2 = $this->serviceableReservation();
        $r2->forceFill(['currency' => null])->saveQuietly();
        FolioCharge::factory()->amount('10.00', 1)->create([
            'reservation_id' => $r2->id, 'hotel_id' => $r2->hotel_id, 'currency' => null,
            'source_id' => fake()->unique()->numberBetween(1, 9_999_999),
        ]);
        $this->assertNull($this->folio()->folioFor($r2->fresh())->currency);
    }
}
