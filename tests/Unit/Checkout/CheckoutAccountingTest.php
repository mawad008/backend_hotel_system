<?php

namespace Tests\Unit\Checkout;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Models\Invoice;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Services\FolioService;

/**
 * Phase 9 review — accommodation in the final folio/invoice, and correct
 * payment accounting (previously captured + final settlement kept as
 * separate transaction rows; `payments.amount` never overwritten).
 */
class CheckoutAccountingTest extends CheckoutTestCase
{
    // ── Scenario A ──────────────────────────────────────────────────
    // Accommodation 1000 + Services 150, previously captured 200
    //   final total 1150 · settlement 950 · payments_total 1150 · outstanding 0

    public function test_scenario_a(): void
    {
        $reservation = $this->inStayReservation(accommodation: '1000.00');
        $this->markPreviouslyCaptured($reservation, '200.00');
        $this->postCharge($reservation, '150.00');

        $result = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Success);

        $this->assertTrue($result->isComplete());
        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);

        // Invoice
        $this->assertSame('1150.00', $result->invoice->subtotal);
        $this->assertSame('1150.00', $result->invoice->payments_total);
        $this->assertSame('0.00', $result->invoice->outstanding_total);

        // Invoice items: accommodation + service, summing to the subtotal.
        $this->assertCount(2, $result->invoice->items);
        $accommodation = $result->invoice->items->firstWhere('description', 'Accommodation');
        $this->assertNotNull($accommodation);
        $this->assertSame('1000.00', $accommodation->total_amount);

        // Payment history preserved: capture 200 + settlement 950 = 1150.
        $this->assertSame('200.00', PaymentTransaction::where('type', PaymentTransaction::TYPE_CAPTURE)->sole()->amount);
        $this->assertSame('950.00', PaymentTransaction::where('type', PaymentTransaction::TYPE_SETTLEMENT)->sole()->amount);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, PaymentTransaction::where('type', 'settlement')->sole()->status);

        // payments.amount (the held deposit) is NOT overwritten.
        $this->assertSame('0.00', $reservation->payment->fresh()->amount);
        $this->assertSame(Payment::STATUS_SETTLED, $reservation->payment->fresh()->status);

        // The live folio now reconciles to the invoice.
        $folio = app(FolioService::class)->folioFor($reservation->fresh());
        $this->assertSame('1150.00', $folio->chargesTotal);
        $this->assertSame('1150.00', $folio->paymentsTotal);
        $this->assertSame('0.00', $folio->outstandingTotal);
    }

    // ── Scenario B ──────────────────────────────────────────────────
    // Accommodation 1000, previously captured 1000 -> no provider call

    public function test_scenario_b(): void
    {
        $reservation = $this->inStayReservation(accommodation: '1000.00');
        $this->markPreviouslyCaptured($reservation, '1000.00');

        $result = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Failure);

        $this->assertTrue($result->isComplete());
        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);
        $this->assertSame(Invoice::STATUS_ISSUED, $result->invoice->status);
        $this->assertSame('1000.00', $result->invoice->subtotal);
        $this->assertSame('1000.00', $result->invoice->payments_total);
        $this->assertSame('0.00', $result->invoice->outstanding_total);

        // NO settlement transaction — the provider was never called.
        $this->assertSame(0, PaymentTransaction::where('type', PaymentTransaction::TYPE_SETTLEMENT)->count());
        // Payment stays CAPTURED (no settlement was owed).
        $this->assertSame(Payment::STATUS_CAPTURED, $reservation->payment->fresh()->status);
    }

    // ── Scenario C ──────────────────────────────────────────────────
    // Accommodation 1000 + Services 200, previously captured 1500 -> credit balance

    public function test_scenario_c(): void
    {
        $reservation = $this->inStayReservation(accommodation: '1000.00');
        $this->markPreviouslyCaptured($reservation, '1500.00');
        $this->postCharge($reservation, '200.00');

        $result = $this->checkoutService()->checkout($reservation);

        $this->assertTrue($result->isComplete());
        $this->assertSame('1200.00', $result->invoice->subtotal);
        $this->assertSame('1500.00', $result->invoice->payments_total);
        $this->assertSame('-300.00', $result->invoice->outstanding_total);

        // No automatic refund invented, no fabricated charge.
        $this->assertSame(0, PaymentTransaction::where('type', PaymentTransaction::TYPE_SETTLEMENT)->count());
        $this->assertSame(0, PaymentTransaction::where('type', PaymentTransaction::TYPE_REFUND)->count());
        $this->assertSame(2, FolioCharge::count()); // accommodation + one service only
        $this->assertSame('0.00', $reservation->payment->fresh()->amount);
    }

    // ── Scenario D ──────────────────────────────────────────────────
    // Repeat successful checkout -> no duplication

    public function test_scenario_d(): void
    {
        $reservation = $this->inStayReservation(accommodation: '1000.00');
        $this->markPreviouslyCaptured($reservation, '200.00');
        $this->postCharge($reservation, '150.00');

        $first = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Success);
        $second = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);
        $third = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Failure);

        $this->assertTrue($first->isComplete() && $second->isComplete() && $third->isComplete());

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Checkout::count());
        $this->assertSame(1, FolioCharge::where('source_type', FolioCharge::SOURCE_ACCOMMODATION)->count());
        $this->assertSame(1, FolioCharge::where('source_type', FolioCharge::SOURCE_SERVICE_ORDER)->count());
        $this->assertSame(1, PaymentTransaction::where('type', PaymentTransaction::TYPE_SETTLEMENT)->count());
        $this->assertSame(1, PaymentTransaction::where('type', PaymentTransaction::TYPE_SETTLEMENT)->where('status', PaymentTransaction::STATUS_SUCCEEDED)->count());

        $this->assertSame($first->invoice->invoice_number, $second->invoice->invoice_number);
        $this->assertSame('1150.00', $second->invoice->subtotal);
        $this->assertSame('1150.00', $second->invoice->payments_total);
        $this->assertSame('0.00', $second->invoice->outstanding_total);
        $this->assertSame(2, $second->invoice->items()->count());
    }

    // ── Scenario E ──────────────────────────────────────────────────
    // Final settlement fails -> nothing final, accommodation deterministic, retry safe

    public function test_scenario_e(): void
    {
        $reservation = $this->inStayReservation(accommodation: '1000.00');
        $this->markPreviouslyCaptured($reservation, '200.00');
        $this->postCharge($reservation, '150.00');

        $failed = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Failure);

        $this->assertFalse($failed->isComplete());
        $this->assertTrue($failed->isSettlementFailed());
        $this->assertSame(Reservation::STATUS_CHECKOUT_IN_PROGRESS, $reservation->fresh()->status);
        $this->assertSame(0, Invoice::count());

        // The accommodation charge was posted deterministically and persists.
        $accommodation = FolioCharge::where('source_type', FolioCharge::SOURCE_ACCOMMODATION)->sole();
        $this->assertSame($reservation->id, (int) $accommodation->source_id);
        $this->assertSame('1000.00', $accommodation->total_amount);

        // Retry succeeds — no duplicate accommodation charge, no duplicate invoice.
        $ok = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);
        $this->assertTrue($ok->isComplete());
        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);
        $this->assertSame(1, FolioCharge::where('source_type', FolioCharge::SOURCE_ACCOMMODATION)->count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame('1150.00', $ok->invoice->payments_total);
        $this->assertSame('0.00', $ok->invoice->outstanding_total);
    }
}
