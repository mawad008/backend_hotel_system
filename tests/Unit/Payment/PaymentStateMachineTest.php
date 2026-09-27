<?php

namespace Tests\Unit\Payment;

use App\Domain\Payment\Exceptions\InvalidPaymentStatusTransitionException;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\StateMachine\PaymentStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 5A — the approved Payment state machine (Phase 0 §9, Phase 5A
 * instructions §10). These tests are the executable copy of the transition
 * table; nothing else in the codebase is allowed to define transition rules.
 */
class PaymentStateMachineTest extends TestCase
{
    /**
     * The complete approved transition table, restated independently of the
     * production constant so drift in either direction fails.
     *
     * @return array<string, list<string>>
     */
    private function approvedMap(): array
    {
        return [
            Payment::STATUS_NOT_STARTED => [
                Payment::STATUS_HOLD_REQUESTED,
            ],
            Payment::STATUS_HOLD_REQUESTED => [
                Payment::STATUS_HOLD_ACTIVE,
                Payment::STATUS_HOLD_FAILED,
                Payment::STATUS_EXPIRED,
                Payment::STATUS_CANCELLED,
            ],
            Payment::STATUS_HOLD_ACTIVE => [
                Payment::STATUS_CAPTURE_REQUESTED,
                Payment::STATUS_EXPIRED,
                Payment::STATUS_CANCELLED,
            ],
            Payment::STATUS_HOLD_FAILED => [
                Payment::STATUS_HOLD_REQUESTED,
            ],
            Payment::STATUS_CAPTURE_REQUESTED => [
                Payment::STATUS_CAPTURED,
                Payment::STATUS_CAPTURE_FAILED,
            ],
            Payment::STATUS_CAPTURE_FAILED => [
                Payment::STATUS_CAPTURE_REQUESTED,
            ],
            Payment::STATUS_CAPTURED => [
                Payment::STATUS_FINAL_SETTLEMENT_REQUESTED,
            ],
            Payment::STATUS_FINAL_SETTLEMENT_REQUESTED => [
                Payment::STATUS_SETTLED,
                Payment::STATUS_SETTLEMENT_FAILED,
            ],
            Payment::STATUS_SETTLEMENT_FAILED => [
                Payment::STATUS_FINAL_SETTLEMENT_REQUESTED,
            ],
            Payment::STATUS_SETTLED => [],
            Payment::STATUS_CANCELLED => [],
            Payment::STATUS_EXPIRED => [],
            Payment::STATUS_REFUND_REQUESTED => [
                Payment::STATUS_REFUNDED,
                Payment::STATUS_REFUND_FAILED,
            ],
            Payment::STATUS_REFUNDED => [],
            Payment::STATUS_REFUND_FAILED => [],
        ];
    }

    // 1. Every approved transition succeeds.

    /**
     * @return array<string, array{string, string}>
     */
    public static function approvedTransitions(): array
    {
        return [
            'NOT_STARTED -> HOLD_REQUESTED' => ['not_started', 'hold_requested'],
            'HOLD_REQUESTED -> HOLD_ACTIVE' => ['hold_requested', 'hold_active'],
            'HOLD_REQUESTED -> HOLD_FAILED' => ['hold_requested', 'hold_failed'],
            'HOLD_REQUESTED -> EXPIRED' => ['hold_requested', 'expired'],
            'HOLD_REQUESTED -> CANCELLED' => ['hold_requested', 'cancelled'],
            'HOLD_ACTIVE -> CAPTURE_REQUESTED' => ['hold_active', 'capture_requested'],
            'HOLD_ACTIVE -> EXPIRED' => ['hold_active', 'expired'],
            'HOLD_ACTIVE -> CANCELLED' => ['hold_active', 'cancelled'],
            'HOLD_FAILED -> HOLD_REQUESTED' => ['hold_failed', 'hold_requested'],
            'CAPTURE_REQUESTED -> CAPTURED' => ['capture_requested', 'captured'],
            'CAPTURE_REQUESTED -> CAPTURE_FAILED' => ['capture_requested', 'capture_failed'],
            'CAPTURE_FAILED -> CAPTURE_REQUESTED' => ['capture_failed', 'capture_requested'],
            'CAPTURED -> FINAL_SETTLEMENT_REQUESTED' => ['captured', 'final_settlement_requested'],
            'FINAL_SETTLEMENT_REQUESTED -> SETTLED' => ['final_settlement_requested', 'settled'],
            'FINAL_SETTLEMENT_REQUESTED -> SETTLEMENT_FAILED' => ['final_settlement_requested', 'settlement_failed'],
            'SETTLEMENT_FAILED -> FINAL_SETTLEMENT_REQUESTED' => ['settlement_failed', 'final_settlement_requested'],
            'REFUND_REQUESTED -> REFUNDED' => ['refund_requested', 'refunded'],
            'REFUND_REQUESTED -> REFUND_FAILED' => ['refund_requested', 'refund_failed'],
        ];
    }

    #[DataProvider('approvedTransitions')]
    public function test_approved_transition_is_allowed(string $from, string $to): void
    {
        $this->assertTrue(PaymentStateMachine::canTransition($from, $to));

        PaymentStateMachine::assertCanTransition($from, $to);
        $this->addToAssertionCount(1);
    }

    // 2. Important invalid transitions fail.

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenTransitions(): array
    {
        return [
            'NOT_STARTED -> HOLD_ACTIVE (skips HOLD_REQUESTED)' => ['not_started', 'hold_active'],
            'NOT_STARTED -> CAPTURED' => ['not_started', 'captured'],
            'NOT_STARTED -> NOT_STARTED (no self-loop)' => ['not_started', 'not_started'],
            'HOLD_REQUESTED -> CAPTURED (skips HOLD_ACTIVE)' => ['hold_requested', 'captured'],
            'HOLD_ACTIVE -> HOLD_REQUESTED (backward)' => ['hold_active', 'hold_requested'],
            'HOLD_ACTIVE -> SETTLED' => ['hold_active', 'settled'],
            'HOLD_ACTIVE -> HOLD_ACTIVE (no self-loop)' => ['hold_active', 'hold_active'],
            'CAPTURED -> SETTLED (skips FINAL_SETTLEMENT_REQUESTED)' => ['captured', 'settled'],
            'no inbound: HOLD_ACTIVE -> REFUND_REQUESTED' => ['hold_active', 'refund_requested'],
            'no inbound: CAPTURED -> REFUND_REQUESTED' => ['captured', 'refund_requested'],
            'no inbound: SETTLED -> REFUND_REQUESTED' => ['settled', 'refund_requested'],
            'terminal SETTLED -> CANCELLED' => ['settled', 'cancelled'],
            'terminal CANCELLED -> HOLD_REQUESTED' => ['cancelled', 'hold_requested'],
            'terminal EXPIRED -> HOLD_REQUESTED' => ['expired', 'hold_requested'],
            'terminal REFUNDED -> anything' => ['refunded', 'hold_requested'],
            'terminal REFUND_FAILED -> anything' => ['refund_failed', 'refund_requested'],
            'unknown source status' => ['not_a_status', 'hold_requested'],
            'unknown target status' => ['hold_requested', 'not_a_status'],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transition_is_rejected(string $from, string $to): void
    {
        $this->assertFalse(PaymentStateMachine::canTransition($from, $to));
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transition_throws_the_dedicated_exception(string $from, string $to): void
    {
        try {
            PaymentStateMachine::assertCanTransition($from, $to);
            $this->fail("Expected {$from} -> {$to} to be rejected.");
        } catch (InvalidPaymentStatusTransitionException $e) {
            $this->assertSame($from, $e->from);
            $this->assertSame($to, $e->to);
            $this->assertStringNotContainsString('array', $e->getMessage());
            $this->assertStringNotContainsString('\\', $e->getMessage());
        }
    }

    // 3. Terminal states.

    public function test_terminal_statuses_have_no_outgoing_transitions(): void
    {
        foreach ([
            Payment::STATUS_SETTLED,
            Payment::STATUS_CANCELLED,
            Payment::STATUS_EXPIRED,
            Payment::STATUS_REFUNDED,
            Payment::STATUS_REFUND_FAILED,
        ] as $status) {
            $this->assertTrue(PaymentStateMachine::isTerminal($status), "{$status} must be terminal");
            $this->assertSame([], PaymentStateMachine::allowedTransitions($status));
        }
    }

    public function test_non_terminal_statuses_report_not_terminal(): void
    {
        foreach ([
            Payment::STATUS_NOT_STARTED,
            Payment::STATUS_HOLD_REQUESTED,
            Payment::STATUS_HOLD_ACTIVE,
            Payment::STATUS_HOLD_FAILED,
            Payment::STATUS_CAPTURE_REQUESTED,
            Payment::STATUS_CAPTURE_FAILED,
            Payment::STATUS_CAPTURED,
            Payment::STATUS_FINAL_SETTLEMENT_REQUESTED,
            Payment::STATUS_SETTLEMENT_FAILED,
            Payment::STATUS_REFUND_REQUESTED,
        ] as $status) {
            $this->assertFalse(PaymentStateMachine::isTerminal($status), "{$status} must not be terminal");
        }
    }

    public function test_unknown_status_is_not_terminal(): void
    {
        $this->assertFalse(PaymentStateMachine::isTerminal('not_a_status'));
    }

    public function test_refund_requested_has_no_inbound_edge_in_phase_5a(): void
    {
        // Refund execution (and its entry point) is deferred - Phase 5A
        // instructions §10: "Do not invent refund transition entry points".
        foreach (PaymentStateMachine::statuses() as $from) {
            $this->assertNotContains(
                Payment::STATUS_REFUND_REQUESTED,
                PaymentStateMachine::allowedTransitions($from),
                "{$from} must not transition into refund_requested",
            );
        }
    }

    // 4. Complete approved transition map.

    public function test_transition_map_matches_the_approved_table_exactly(): void
    {
        $this->assertSame($this->approvedMap(), PaymentStateMachine::TRANSITIONS);
    }

    public function test_statuses_are_exactly_the_model_status_constants(): void
    {
        $constants = Payment::STATUSES;
        sort($constants);

        $nodes = PaymentStateMachine::statuses();
        sort($nodes);

        $this->assertSame($constants, $nodes);
    }

    public function test_no_transition_targets_an_unknown_status_or_itself(): void
    {
        $known = PaymentStateMachine::statuses();

        foreach (PaymentStateMachine::TRANSITIONS as $from => $targets) {
            foreach ($targets as $target) {
                $this->assertContains($target, $known, "{$from} -> {$target} targets an unknown status");
                $this->assertNotSame($from, $target, "{$from} must not transition to itself");
            }
        }
    }

    public function test_exhaustive_can_transition_matches_the_approved_map(): void
    {
        $map = $this->approvedMap();

        foreach (PaymentStateMachine::statuses() as $from) {
            foreach (PaymentStateMachine::statuses() as $to) {
                $expected = in_array($to, $map[$from], true);

                $this->assertSame(
                    $expected,
                    PaymentStateMachine::canTransition($from, $to),
                    "canTransition({$from}, {$to}) should be ".($expected ? 'true' : 'false'),
                );
            }
        }
    }

    public function test_initial_status_is_not_started(): void
    {
        $this->assertSame(Payment::STATUS_NOT_STARTED, PaymentStateMachine::INITIAL_STATUS);
    }
}
