<?php

namespace Tests\Unit\Payment\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Repositories\EloquentPaymentTransactionRepository;
use App\Domain\Reservation\Models\Reservation;
use RuntimeException;
use Tests\Unit\Payment\Workflow\Fakes\ConditionalThrowingAuditLogger;
use Tests\Unit\Payment\Workflow\Fakes\RecordingPaymentGateway;

/**
 * Phase 5C test matrix I, M and §41 / §42 — the A→B→C boundary, stale-state
 * re-read, late success after cancellation, and rollback behaviour.
 */
class PaymentWorkflowConcurrencyTest extends PaymentWorkflowTestCase
{
    // ── §41. Late success after the reservation was cancelled ───────

    public function test_late_provider_success_after_cancellation_never_reactivates_the_reservation(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Succeeded);
        $gateway->onInitiateHold = function () use ($reservation) {
            // A concurrent request cancels the reservation after Step A
            // commits but before the provider result is applied.
            Reservation::whereKey($reservation->id)->update(['status' => Reservation::STATUS_CANCELLED]);
        };

        $payment = $this->makeWorkflow($gateway)->initiateHold(
            $reservation, '120.00', null, 'late', SimulationDirective::Success, null,
        );

        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->status);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, $payment->transactions()->sole()->status);

        // No auto-refund, no invented column, no reservation reactivation.
        $this->assertNull(AuditLog::where('action', 'like', '%refund%')->first());
        $this->assertArrayNotHasKey('needs_refund', $payment->fresh()->getAttributes());

        $late = AuditLog::where('action', 'payment.hold_succeeded_after_reservation_closed')->sole();
        $this->assertTrue($late->after['requires_reconciliation']);
        $this->assertSame(Reservation::STATUS_CANCELLED, $late->after['reservation_status']);
        $this->assertNull(
            AuditLog::where('action', 'reservation.status_changed')
                ->where('auditable_id', $reservation->id)
                ->first(),
            'the workflow must not transition the reservation on a late success',
        );
    }

    public function test_late_provider_failure_after_cancellation_leaves_reservation_cancelled(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Failed);
        $gateway->onInitiateHold = function () use ($reservation) {
            Reservation::whereKey($reservation->id)->update(['status' => Reservation::STATUS_CANCELLED]);
        };

        $payment = $this->makeWorkflow($gateway)->initiateHold(
            $reservation, '120.00', null, null, SimulationDirective::Failure, null,
        );

        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_HOLD_FAILED, $payment->status);
        // No second, invalid CANCELLED -> CANCELLED transition was attempted.
        $this->assertNull(
            AuditLog::where('action', 'reservation.status_changed')
                ->where('auditable_id', $reservation->id)
                ->first(),
        );
    }

    // ── I. Stale-state re-read ─────────────────────────────────────

    public function test_step_c_reads_reservation_state_fresh_not_from_step_a(): void
    {
        // The reservation advances to DEPOSIT_HELD (as if by another
        // successful hold path) while the provider call is in flight; the
        // late success must not attempt PENDING -> DEPOSIT_HELD again.
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Succeeded);
        $gateway->onInitiateHold = function () use ($reservation) {
            Reservation::whereKey($reservation->id)->update(['status' => Reservation::STATUS_DEPOSIT_HELD]);
        };

        $payment = $this->makeWorkflow($gateway)->initiateHold(
            $reservation, '10.00', null, null, SimulationDirective::Success, null,
        );

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
        $this->assertNotNull(AuditLog::where('action', 'payment.hold_succeeded_after_reservation_closed')->first());
    }

    // ── §42. Crash boundary between Step A and Step C ──────────────

    public function test_a_crash_after_step_a_leaves_a_recoverable_pending_transaction(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $gateway = new RecordingPaymentGateway;
        $gateway->beforeReturn = function () {
            throw new RuntimeException('provider call crashed');
        };

        try {
            $this->makeWorkflow($gateway)->initiateHold($reservation, '80.00', null, 'crash', SimulationDirective::Success, null);
            $this->fail('Expected the provider crash to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('provider call crashed', $e->getMessage());
        }

        // Step A is durable — the attempt is externally recoverable later.
        $payment = $reservation->fresh()->payment;
        $this->assertNotNull($payment);
        $this->assertSame(Payment::STATUS_HOLD_REQUESTED, $payment->status);

        $transaction = $payment->transactions()->sole();
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->status);
        $this->assertSame('crash', $transaction->idempotency_key);

        // The reservation is untouched — still awaiting a resolved hold.
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    // ── M. Rollback ───────────────────────────────────────────────

    public function test_step_a_failure_leaves_no_partial_payment_or_transaction(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $brokenTransactions = new class extends EloquentPaymentTransactionRepository
        {
            public function create(array $data): PaymentTransaction
            {
                throw new RuntimeException('transaction insert blew up');
            }
        };

        $gateway = new RecordingPaymentGateway;

        try {
            $this->makeWorkflow($gateway, transactions: $brokenTransactions)
                ->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);
            $this->fail('Expected the transaction insert failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('transaction insert blew up', $e->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame(0, $gateway->initiateHoldCalls);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_step_c_failure_rolls_back_every_change_it_made(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $audit = new ConditionalThrowingAuditLogger('payment.hold_succeeded');
        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Succeeded);

        try {
            $this->makeWorkflow($gateway, $audit)
                ->initiateHold($reservation, '10.00', null, 'sc', SimulationDirective::Success, null);
            $this->fail('Expected the Step C audit failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('audit failure', $e->getMessage());
        }

        // Step A survives; Step C is fully rolled back.
        $payment = $reservation->fresh()->payment;
        $this->assertSame(Payment::STATUS_HOLD_REQUESTED, $payment->status);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $payment->transactions()->sole()->status);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
        $this->assertSame(1, $gateway->initiateHoldCalls);
    }
}
