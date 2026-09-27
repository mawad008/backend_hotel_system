<?php

namespace Tests\Unit\Payment\Workflow;

use App\Domain\Payment\Exceptions\IdempotencyKeyConflictException;
use App\Domain\Payment\Exceptions\PaymentAlreadyInitiatedException;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use Tests\Unit\Payment\Workflow\Fakes\RecordingPaymentGateway;

/**
 * Phase 5C test matrix G, H — DB-backed idempotency (the
 * payment_transactions.idempotency_key UNIQUE constraint) and the
 * one-Payment-per-Reservation guarantee.
 */
class PaymentWorkflowIdempotencyTest extends PaymentWorkflowTestCase
{
    // ── G. Idempotency ─────────────────────────────────────────────

    public function test_same_key_replayed_after_success_returns_the_same_payment_without_a_second_provider_call(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Succeeded);
        $workflow = $this->makeWorkflow($gateway);

        $first = $workflow->initiateHold($reservation, '10.00', null, 'idem-key', SimulationDirective::Success, null);
        $second = $workflow->initiateHold($reservation->fresh(), '10.00', null, 'idem-key', SimulationDirective::Success, null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $second->status);
        $this->assertSame(1, $gateway->initiateHoldCalls);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_same_key_replayed_after_failure_returns_the_failed_payment_and_does_not_retry(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Failed);
        $workflow = $this->makeWorkflow($gateway);

        $workflow->initiateHold($reservation, '10.00', null, 'k', SimulationDirective::Failure, null);
        $replay = $workflow->initiateHold($reservation->fresh(), '10.00', null, 'k', SimulationDirective::Failure, null);

        $this->assertSame(Payment::STATUS_HOLD_FAILED, $replay->status);
        $this->assertSame(1, $gateway->initiateHoldCalls);
        $this->assertDatabaseCount('payment_transactions', 1);
        // The reservation was cancelled by the failure and stays cancelled.
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
    }

    public function test_same_key_replayed_while_still_pending_does_not_make_a_second_provider_call(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Pending);
        $workflow = $this->makeWorkflow($gateway);

        $workflow->initiateHold($reservation, '10.00', null, 'k', SimulationDirective::Pending, null);
        $replay = $workflow->initiateHold($reservation->fresh(), '10.00', null, 'k', SimulationDirective::Pending, null);

        $this->assertSame(Payment::STATUS_HOLD_REQUESTED, $replay->status);
        $this->assertSame(1, $gateway->initiateHoldCalls);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $replay->transactions()->sole()->status);
    }

    public function test_same_key_for_a_different_reservation_is_a_conflict(): void
    {
        $reservationA = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $reservationB = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $workflow = $this->makeWorkflow();

        $workflow->initiateHold($reservationA, '10.00', null, 'shared-key', SimulationDirective::Success, null);

        try {
            $workflow->initiateHold($reservationB, '10.00', null, 'shared-key', SimulationDirective::Success, null);
            $this->fail('Expected an IdempotencyKeyConflictException.');
        } catch (IdempotencyKeyConflictException $e) {
            $this->assertSame('different reservation', $e->reason);
        }

        $this->assertNull($reservationB->fresh()->payment);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_same_key_for_a_different_provider_is_a_conflict(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $payment = Payment::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'status' => Payment::STATUS_HOLD_REQUESTED,
        ]);
        PaymentTransaction::factory()->create([
            'payment_id' => $payment->id,
            'idempotency_key' => 'legacy-key',
            'provider' => 'some_other_provider',
            'type' => PaymentTransaction::TYPE_HOLD,
        ]);

        $this->expectException(IdempotencyKeyConflictException::class);

        $this->makeWorkflow()->initiateHold($reservation, '10.00', null, 'legacy-key', SimulationDirective::Success, null);
    }

    public function test_same_key_for_a_different_operation_type_is_a_conflict(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $payment = Payment::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'status' => Payment::STATUS_HOLD_ACTIVE,
        ]);
        PaymentTransaction::factory()->create([
            'payment_id' => $payment->id,
            'idempotency_key' => 'capture-key',
            'provider' => 'dummy',
            'type' => PaymentTransaction::TYPE_CAPTURE,
        ]);

        try {
            $this->makeWorkflow()->initiateHold($reservation, '10.00', null, 'capture-key', SimulationDirective::Success, null);
            $this->fail('Expected an IdempotencyKeyConflictException.');
        } catch (IdempotencyKeyConflictException $e) {
            $this->assertSame('different operation type', $e->reason);
        }
    }

    public function test_a_fresh_uuid_key_is_used_when_the_caller_supplies_none(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $payment = $this->makeWorkflow()->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);

        $key = $payment->transactions()->sole()->idempotency_key;
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $key);
    }

    // ── H. One Payment per Reservation ─────────────────────────────

    public function test_a_second_differently_keyed_initiation_cannot_create_a_second_payment(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Pending);
        $workflow = $this->makeWorkflow($gateway);

        $workflow->initiateHold($reservation, '10.00', null, 'key-1', SimulationDirective::Pending, null);

        $this->expectException(PaymentAlreadyInitiatedException::class);

        try {
            $workflow->initiateHold($reservation->fresh(), '10.00', null, 'key-2', SimulationDirective::Pending, null);
        } finally {
            $this->assertDatabaseCount('payments', 1);
            $this->assertSame(1, $gateway->initiateHoldCalls);
        }
    }

    public function test_unique_reservation_id_race_is_handled_without_a_duplicate_row(): void
    {
        // Simulate the §26 race: a Payment already exists (as if a
        // concurrent request's Step A committed first) and the current
        // call's create() would hit UNIQUE(reservation_id).
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        Payment::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'status' => Payment::STATUS_HOLD_REQUESTED,
        ]);

        $this->expectException(PaymentAlreadyInitiatedException::class);

        try {
            $this->makeWorkflow()->initiateHold($reservation, '10.00', null, 'new-key', SimulationDirective::Success, null);
        } finally {
            $this->assertDatabaseCount('payments', 1);
        }
    }
}
