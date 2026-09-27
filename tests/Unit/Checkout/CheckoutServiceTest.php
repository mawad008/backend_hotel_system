<?php

namespace Tests\Unit\Checkout;

use App\Domain\Checkout\Exceptions\CheckoutCurrencyMissingException;
use App\Domain\Checkout\Exceptions\CheckoutNotAllowedException;
use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Models\Invoice;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use PHPUnit\Framework\Attributes\DataProvider;

class CheckoutServiceTest extends CheckoutTestCase
{
    // ── Case 1: no outstanding amount ────────────────────────────────

    public function test_no_outstanding_completes_without_a_provider_call(): void
    {
        $reservation = $this->inStayReservation();

        $result = $this->checkoutService()->checkout($reservation);

        $this->assertTrue($result->isComplete());
        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);
        $this->assertSame(Invoice::STATUS_ISSUED, $result->invoice->status);
        $this->assertSame('0.00', $result->invoice->subtotal);
        $this->assertSame('0.00', $result->invoice->outstanding_total);
        // No settlement transaction was ever created.
        $this->assertDatabaseCount('payment_transactions', 0);
        // Payment left untouched.
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $reservation->payment->fresh()->status);
    }

    // ── Case 2: outstanding > 0, provider SUCCESS ────────────────────

    public function test_outstanding_with_successful_settlement_completes_checkout(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '60.00');

        $result = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Success);

        $this->assertTrue($result->isComplete());
        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_SETTLED, $reservation->payment->fresh()->status);
        // payments.amount keeps its Phase 5 meaning (the deposit) — NOT overwritten.
        $this->assertSame('0.00', $reservation->payment->fresh()->amount);
        $this->assertSame('60.00', $result->invoice->subtotal);
        $this->assertSame('60.00', $result->invoice->payments_total);
        $this->assertSame('0.00', $result->invoice->outstanding_total);

        $txn = PaymentTransaction::sole();
        $this->assertSame(PaymentTransaction::TYPE_SETTLEMENT, $txn->type);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, $txn->status);
        // the settlement transaction records the outstanding actually collected
        $this->assertSame('60.00', $txn->amount);
    }

    public function test_captured_payment_path_also_settles(): void
    {
        $reservation = $this->inStayReservation();
        $this->markPreviouslyCaptured($reservation, '20.00'); // deposit captured before checkout
        $this->postCharge($reservation, '50.00'); // charges 50, captured 20 -> outstanding 30

        $result = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Success);

        $this->assertTrue($result->isComplete());
        $this->assertSame(Payment::STATUS_SETTLED, $reservation->payment->fresh()->status);
        // the prior 20 capture + the 30 settlement = 50 collected; both preserved as separate rows
        $this->assertSame('20.00', PaymentTransaction::where('type', PaymentTransaction::TYPE_CAPTURE)->sole()->amount);
        $this->assertSame('30.00', PaymentTransaction::where('type', PaymentTransaction::TYPE_SETTLEMENT)->sole()->amount);
        $this->assertSame('50.00', $result->invoice->payments_total);
        $this->assertSame('0.00', $result->invoice->outstanding_total);
    }

    // ── Cases 3-6: provider does not succeed -> no final state ───────

    /**
     * @return array<string, array{SimulationDirective, string}>
     */
    public static function nonSuccessfulSettlements(): array
    {
        return [
            'failure' => [SimulationDirective::Failure, Checkout::STATUS_SETTLEMENT_FAILED],
            'cancelled' => [SimulationDirective::Cancelled, Checkout::STATUS_SETTLEMENT_FAILED],
            'expired' => [SimulationDirective::Expired, Checkout::STATUS_SETTLEMENT_FAILED],
            'pending' => [SimulationDirective::Pending, Checkout::STATUS_AWAITING_SETTLEMENT],
        ];
    }

    #[DataProvider('nonSuccessfulSettlements')]
    public function test_non_successful_settlement_never_finalizes_checkout(
        SimulationDirective $directive,
        string $expectedCheckoutStatus,
    ): void {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '75.00');

        $result = $this->checkoutService()->checkout($reservation, directive: $directive);

        $this->assertFalse($result->isComplete());
        $this->assertSame($expectedCheckoutStatus, $result->checkout->status);

        // The reservation MUST stay in the safe non-final state.
        $this->assertSame(Reservation::STATUS_CHECKOUT_IN_PROGRESS, $reservation->fresh()->status);

        // No invoice was issued.
        $this->assertDatabaseCount('invoices', 0);

        // The payment is non-settled.
        $this->assertNotSame(Payment::STATUS_SETTLED, $reservation->payment->fresh()->status);
    }

    public function test_failed_settlement_records_the_transaction_and_is_retryable(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '40.00');

        $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Failure);

        $this->assertSame(Payment::STATUS_SETTLEMENT_FAILED, $reservation->payment->fresh()->status);
        $this->assertSame(PaymentTransaction::STATUS_FAILED, PaymentTransaction::latest('id')->first()->status);

        // Retry — a fresh key, the state machine allows SETTLEMENT_FAILED -> FINAL_SETTLEMENT_REQUESTED.
        $result = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);

        $this->assertTrue($result->isComplete());
        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_SETTLED, $reservation->payment->fresh()->status);
        // The failed attempt row stays as history; the second one succeeded.
        $this->assertSame(2, PaymentTransaction::count());
        $this->assertSame(1, PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCEEDED)->count());
        // Still exactly one invoice.
        $this->assertSame(1, Invoice::count());
    }

    public function test_pending_settlement_can_be_completed_on_a_later_retry(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '25.00');

        $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Pending);
        $this->assertSame(Checkout::STATUS_AWAITING_SETTLEMENT, Checkout::sole()->status);

        $result = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);
        $this->assertTrue($result->isComplete());
        $this->assertSame(1, Invoice::count());
    }

    // ── Case 7: idempotent repeated checkout ─────────────────────────

    public function test_repeated_successful_checkout_is_idempotent(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '30.00');

        $first = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Success);
        $second = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);
        $third = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Failure);

        $this->assertTrue($first->isComplete() && $second->isComplete() && $third->isComplete());
        $this->assertSame($first->invoice->id, $second->invoice->id);
        $this->assertSame($first->invoice->invoice_number, $third->invoice->invoice_number);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Checkout::count());
        // Exactly one successful settlement — no double charge.
        $this->assertSame(1, PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCEEDED)->count());
    }

    public function test_same_idempotency_key_never_creates_a_second_settlement_transaction(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '30.00');

        $this->checkoutService()->checkout($reservation, idempotencyKey: 'co-key-1', directive: SimulationDirective::Success);
        $this->checkoutService()->checkout($reservation->fresh(), idempotencyKey: 'co-key-1', directive: SimulationDirective::Success);

        $this->assertSame(1, PaymentTransaction::where('idempotency_key', 'co-key-1')->count());
        $this->assertSame(1, PaymentTransaction::count());
    }

    // ── Eligibility / state ─────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function nonCheckoutableStatuses(): array
    {
        return [
            'pending' => [Reservation::STATUS_PENDING],
            'deposit_held' => [Reservation::STATUS_DEPOSIT_HELD],
            'verified' => [Reservation::STATUS_VERIFIED],
            'checked_in' => [Reservation::STATUS_CHECKED_IN],
            'cancelled' => [Reservation::STATUS_CANCELLED],
            'checkout_blocked' => [Reservation::STATUS_CHECKOUT_BLOCKED],
        ];
    }

    #[DataProvider('nonCheckoutableStatuses')]
    public function test_checkout_is_rejected_from_a_non_in_stay_status(string $status): void
    {
        $reservation = $this->inStayReservation();
        $reservation->update(['status' => $status]);

        $this->expectException(CheckoutNotAllowedException::class);
        $this->checkoutService()->checkout($reservation);
    }

    public function test_already_invoiced_reservation_is_an_idempotent_replay(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '10.00');
        $done = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Success);

        $again = $this->checkoutService()->checkout($reservation->fresh());

        $this->assertTrue($again->isComplete());
        $this->assertSame($done->invoice->id, $again->invoice->id);
        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);
    }

    public function test_checkout_resumes_from_checkout_in_progress(): void
    {
        $reservation = $this->inStayReservation();
        // Simulate a prior attempt that started but did not finish.
        $reservation->update(['status' => Reservation::STATUS_CHECKOUT_IN_PROGRESS]);
        Checkout::factory()->create(['reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id]);

        $result = $this->checkoutService()->checkout($reservation);

        $this->assertTrue($result->isComplete());
        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);
    }

    // ── Negative outstanding ────────────────────────────────────────

    public function test_negative_outstanding_completes_without_settlement_or_a_fabricated_charge(): void
    {
        $reservation = $this->inStayReservation();
        $this->markPreviouslyCaptured($reservation, '100.00'); // 100 already collected
        $this->postCharge($reservation, '30.00'); // charges 30, collected 100 -> outstanding -70

        $result = $this->checkoutService()->checkout($reservation);

        $this->assertTrue($result->isComplete());
        $this->assertSame('-70.00', $result->invoice->outstanding_total);
        $this->assertSame('30.00', $result->invoice->subtotal);
        $this->assertSame('100.00', $result->invoice->payments_total);
        // No settlement transaction — no provider call for a credit balance.
        $this->assertSame(0, PaymentTransaction::where('type', PaymentTransaction::TYPE_SETTLEMENT)->count());
        // No new folio charge fabricated for the credit balance (only the service charge).
        $this->assertSame(1, FolioCharge::count());
        $this->assertSame(Payment::STATUS_CAPTURED, $reservation->payment->fresh()->status);
        // payments.amount untouched.
        $this->assertSame('0.00', $reservation->payment->fresh()->amount);
    }

    // ── Currency ────────────────────────────────────────────────────

    public function test_settlement_with_no_resolvable_currency_fails_safely(): void
    {
        $reservation = $this->inStayReservation();
        // A legacy (pre-snapshot) reservation with no currency anywhere.
        $reservation->forceFill(['currency' => null])->saveQuietly();
        $reservation->payment->update(['currency' => null]);
        FolioCharge::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'source_type' => FolioCharge::SOURCE_SERVICE_ORDER,
            'source_id' => 991,
            'currency' => null,
            'unit_amount' => '20.00',
            'total_amount' => '20.00',
            'quantity' => 1,
            'status' => FolioCharge::STATUS_POSTED,
        ]);

        try {
            $this->checkoutService()->checkout($reservation);
            $this->fail('expected CheckoutCurrencyMissingException');
        } catch (CheckoutCurrencyMissingException) {
            // STEP A rolled back entirely — nothing was persisted and the
            // reservation is untouched. A clean retry is possible.
            $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);
            $this->assertDatabaseCount('checkouts', 0);
            $this->assertDatabaseCount('invoices', 0);
        }
    }
}
