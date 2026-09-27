<?php

namespace Tests\Unit\Payment\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 5C test matrix A–E, J, K — the deposit hold flow end to end,
 * driven by the real DummyPaymentGateway simulation directives.
 */
class PaymentWorkflowHoldFlowTest extends PaymentWorkflowTestCase
{
    // ── A. Happy path ────────────────────────────────────────────────

    public function test_successful_hold_advances_payment_transaction_and_reservation(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $actor = User::factory()->groupOwner()->create();

        $payment = $this->makeWorkflow()->initiateHold(
            $reservation, '150.00', 'EUR', 'idem-happy', SimulationDirective::Success, $actor,
        );

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->status);
        $this->assertSame('150.00', $payment->amount);
        $this->assertSame('EUR', $payment->currency);
        $this->assertSame($reservation->hotel_id, $payment->hotel_id);
        $this->assertSame($reservation->id, $payment->reservation_id);

        $transaction = $payment->transactions()->sole();
        $this->assertSame(PaymentTransaction::TYPE_HOLD, $transaction->type);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, $transaction->status);
        $this->assertSame('idem-happy', $transaction->idempotency_key);
        $this->assertSame('dummy', $transaction->provider);
        $this->assertNotNull($transaction->provider_reference);
        $this->assertSame($actor->id, $transaction->requested_by_user_id);

        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }

    public function test_successful_hold_writes_the_expected_audit_trail(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $actor = User::factory()->groupOwner()->create();

        $this->makeWorkflow()->initiateHold($reservation, 150, null, 'idem', SimulationDirective::Success, $actor);

        $actions = AuditLog::orderBy('id')->pluck('action')->all();
        $this->assertContains('payment.hold_requested', $actions);
        $this->assertContains('payment.hold_succeeded', $actions);
        $this->assertContains('reservation.status_changed', $actions);

        $succeeded = AuditLog::where('action', 'payment.hold_succeeded')->sole();
        $this->assertSame($reservation->hotel_id, $succeeded->hotel_id);
        $this->assertSame($actor->id, $succeeded->actor_id);
        $this->assertSame(Payment::STATUS_HOLD_REQUESTED, $succeeded->before['payment_status']);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $succeeded->after['payment_status']);
    }

    // ── B–D. Failure / cancelled / expired all cancel the reservation ──

    /**
     * @return array<string, array{SimulationDirective, string, string}>
     */
    public static function terminalFailureCases(): array
    {
        return [
            'failure' => [SimulationDirective::Failure, PaymentTransaction::STATUS_FAILED, Payment::STATUS_HOLD_FAILED],
            'cancelled' => [SimulationDirective::Cancelled, PaymentTransaction::STATUS_CANCELLED, Payment::STATUS_CANCELLED],
            'expired' => [SimulationDirective::Expired, PaymentTransaction::STATUS_EXPIRED, Payment::STATUS_EXPIRED],
        ];
    }

    #[DataProvider('terminalFailureCases')]
    public function test_terminal_provider_outcome_fails_payment_and_cancels_reservation(
        SimulationDirective $directive,
        string $expectedTransactionStatus,
        string $expectedPaymentStatus,
    ): void {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $payment = $this->makeWorkflow()->initiateHold($reservation, '99.00', null, null, $directive, null);

        $this->assertSame($expectedPaymentStatus, $payment->status);
        $this->assertSame($expectedTransactionStatus, $payment->transactions()->sole()->status);
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
    }

    public function test_failure_never_leaves_the_workflow_half_applied(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $this->makeWorkflow()->initiateHold($reservation, '99.00', null, null, SimulationDirective::Failure, null);

        // The exact assertion mandated by Phase 5C §40.
        $this->assertNotSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
        $this->assertDatabaseMissing('payments', ['reservation_id' => $reservation->id, 'status' => Payment::STATUS_HOLD_REQUESTED]);
        $this->assertDatabaseMissing('payment_transactions', ['status' => PaymentTransaction::STATUS_PENDING]);
    }

    public function test_failed_hold_is_audited(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $this->makeWorkflow()->initiateHold($reservation, '99.00', null, null, SimulationDirective::Failure, null);

        $this->assertNotNull(AuditLog::where('action', 'payment.hold_failed')->first());
        $this->assertNotNull(
            AuditLog::where('action', 'reservation.status_changed')
                ->where('auditable_id', $reservation->id)
                ->first()
        );
    }

    // ── E. Pending ──────────────────────────────────────────────────

    public function test_pending_provider_outcome_holds_every_state_still(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $payment = $this->makeWorkflow()->initiateHold($reservation, '99.00', null, 'idem-pending', SimulationDirective::Pending, null);

        $this->assertSame(Payment::STATUS_HOLD_REQUESTED, $payment->status);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);

        $transaction = $payment->transactions()->sole();
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->status);
        // The provider reference is still retained for later webhook correlation.
        $this->assertNotNull($transaction->provider_reference);

        $this->assertNotNull(AuditLog::where('action', 'payment.hold_pending')->first());
        $this->assertNull(AuditLog::where('action', 'reservation.status_changed')->first());
    }

    // ── J / K. State-machine + transaction-status independence ───────

    public function test_payment_status_and_transaction_status_are_tracked_independently(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $payment = $this->makeWorkflow()->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);

        // Payment carries a Payment-domain status; the transaction carries a
        // provider-attempt status — different vocabularies.
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->status);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, $payment->transactions()->sole()->status);
        $this->assertContains($payment->status, Payment::STATUSES);
        $this->assertContains($payment->transactions()->sole()->status, PaymentTransaction::STATUSES);
    }

    public function test_only_one_transaction_is_created_per_hold(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $this->makeWorkflow()->initiateHold($reservation, '10.00', null, 'idem', SimulationDirective::Success, null);

        $this->assertDatabaseCount('payment_transactions', 1);
    }
}
