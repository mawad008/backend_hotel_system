<?php

namespace Tests\Unit\Reservation;

use App\Domain\Reservation\Exceptions\InvalidReservationStatusTransitionException;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\StateMachine\ReservationStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 4A — the approved Reservation state machine (Phase 0 §8). These
 * tests are the executable copy of the transition table; nothing else in
 * the codebase is allowed to define transition rules.
 */
class ReservationStateMachineTest extends TestCase
{
    /**
     * The complete approved transition table, restated here independently
     * of the production constant so a drift in either direction fails.
     *
     * @return array<string, list<string>>
     */
    private function approvedMap(): array
    {
        return [
            Reservation::STATUS_PENDING => [
                Reservation::STATUS_DEPOSIT_HELD,
                Reservation::STATUS_CANCELLED,
            ],
            Reservation::STATUS_DEPOSIT_HELD => [
                Reservation::STATUS_VERIFIED,
                Reservation::STATUS_CANCELLED,
            ],
            Reservation::STATUS_VERIFIED => [
                Reservation::STATUS_CHECKED_IN,
                Reservation::STATUS_CANCELLED,
            ],
            Reservation::STATUS_CHECKED_IN => [
                Reservation::STATUS_IN_STAY,
            ],
            Reservation::STATUS_IN_STAY => [
                Reservation::STATUS_CHECKOUT_IN_PROGRESS,
            ],
            Reservation::STATUS_CHECKOUT_IN_PROGRESS => [
                Reservation::STATUS_CHECKED_OUT,
                Reservation::STATUS_CHECKOUT_BLOCKED,
            ],
            Reservation::STATUS_CHECKOUT_BLOCKED => [],
            Reservation::STATUS_CHECKED_OUT => [
                Reservation::STATUS_INVOICED,
            ],
            Reservation::STATUS_INVOICED => [],
            Reservation::STATUS_CANCELLED => [],
        ];
    }

    // ---------------------------------------------------------------------
    // 1. Every approved transition succeeds.
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function approvedTransitions(): array
    {
        return [
            'PENDING -> DEPOSIT_HELD' => ['pending', 'deposit_held'],
            'PENDING -> CANCELLED' => ['pending', 'cancelled'],
            'DEPOSIT_HELD -> VERIFIED' => ['deposit_held', 'verified'],
            'DEPOSIT_HELD -> CANCELLED' => ['deposit_held', 'cancelled'],
            'VERIFIED -> CHECKED_IN' => ['verified', 'checked_in'],
            'VERIFIED -> CANCELLED' => ['verified', 'cancelled'],
            'CHECKED_IN -> IN_STAY' => ['checked_in', 'in_stay'],
            'IN_STAY -> CHECKOUT_IN_PROGRESS' => ['in_stay', 'checkout_in_progress'],
            'CHECKOUT_IN_PROGRESS -> CHECKED_OUT' => ['checkout_in_progress', 'checked_out'],
            'CHECKOUT_IN_PROGRESS -> CHECKOUT_BLOCKED' => ['checkout_in_progress', 'checkout_blocked'],
            'CHECKED_OUT -> INVOICED' => ['checked_out', 'invoiced'],
        ];
    }

    #[DataProvider('approvedTransitions')]
    public function test_approved_transition_is_allowed(string $from, string $to): void
    {
        $this->assertTrue(ReservationStateMachine::canTransition($from, $to));

        // The guard must not throw for an approved transition.
        ReservationStateMachine::assertCanTransition($from, $to);
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------
    // 2. Important invalid transitions fail.
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenTransitions(): array
    {
        return [
            'PENDING -> VERIFIED (skips DEPOSIT_HELD)' => ['pending', 'verified'],
            'PENDING -> CHECKED_IN' => ['pending', 'checked_in'],
            'PENDING -> PENDING (no self-loop)' => ['pending', 'pending'],
            'DEPOSIT_HELD -> CHECKED_IN (skips VERIFIED)' => ['deposit_held', 'checked_in'],
            'VERIFIED -> IN_STAY (skips CHECKED_IN)' => ['verified', 'in_stay'],
            'CHECKED_IN -> CANCELLED (no cancel after check-in)' => ['checked_in', 'cancelled'],
            'IN_STAY -> CANCELLED' => ['in_stay', 'cancelled'],
            'CHECKOUT_IN_PROGRESS -> CANCELLED' => ['checkout_in_progress', 'cancelled'],
            'CHECKED_OUT -> CANCELLED' => ['checked_out', 'cancelled'],
            'CHECKOUT_BLOCKED -> CHECKED_OUT (no approved outgoing arrow)' => ['checkout_blocked', 'checked_out'],
            'CHECKOUT_BLOCKED -> CANCELLED' => ['checkout_blocked', 'cancelled'],
            'INVOICED -> CANCELLED' => ['invoiced', 'cancelled'],
            'INVOICED -> CHECKED_OUT' => ['invoiced', 'checked_out'],
            'INVOICED -> PENDING' => ['invoiced', 'pending'],
            'CANCELLED -> PENDING' => ['cancelled', 'pending'],
            'CANCELLED -> DEPOSIT_HELD' => ['cancelled', 'deposit_held'],
            'CANCELLED -> CANCELLED' => ['cancelled', 'cancelled'],
            'backwards: DEPOSIT_HELD -> PENDING' => ['deposit_held', 'pending'],
            'backwards: IN_STAY -> CHECKED_IN' => ['in_stay', 'checked_in'],
            'unknown source status' => ['not_a_status', 'pending'],
            'unknown target status' => ['pending', 'not_a_status'],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transition_is_rejected(string $from, string $to): void
    {
        $this->assertFalse(ReservationStateMachine::canTransition($from, $to));
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transition_throws_the_dedicated_exception(string $from, string $to): void
    {
        try {
            ReservationStateMachine::assertCanTransition($from, $to);
            $this->fail("Expected {$from} -> {$to} to be rejected.");
        } catch (InvalidReservationStatusTransitionException $e) {
            $this->assertSame($from, $e->from);
            $this->assertSame($to, $e->to);
            // The message must not leak internal implementation detail.
            $this->assertStringNotContainsString('array', $e->getMessage());
            $this->assertStringNotContainsString('\\', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // 3. Terminal states.
    // ---------------------------------------------------------------------

    public function test_invoiced_is_terminal(): void
    {
        $this->assertTrue(ReservationStateMachine::isTerminal(Reservation::STATUS_INVOICED));
        $this->assertSame([], ReservationStateMachine::allowedTransitions(Reservation::STATUS_INVOICED));
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertTrue(ReservationStateMachine::isTerminal(Reservation::STATUS_CANCELLED));
        $this->assertSame([], ReservationStateMachine::allowedTransitions(Reservation::STATUS_CANCELLED));
    }

    public function test_checkout_blocked_has_no_outgoing_transition_in_phase_4a(): void
    {
        // Phase 0 §8 marks CHECKOUT_BLOCKED "(staff-resolved)" but defines
        // no outgoing arrow. Phase 4A does not invent one — see the open
        // workflow item in the Phase 4A report.
        $this->assertTrue(ReservationStateMachine::isTerminal(Reservation::STATUS_CHECKOUT_BLOCKED));
        $this->assertSame([], ReservationStateMachine::allowedTransitions(Reservation::STATUS_CHECKOUT_BLOCKED));
    }

    public function test_non_terminal_states_report_not_terminal(): void
    {
        foreach ([
            Reservation::STATUS_PENDING,
            Reservation::STATUS_DEPOSIT_HELD,
            Reservation::STATUS_VERIFIED,
            Reservation::STATUS_CHECKED_IN,
            Reservation::STATUS_IN_STAY,
            Reservation::STATUS_CHECKOUT_IN_PROGRESS,
            Reservation::STATUS_CHECKED_OUT,
        ] as $status) {
            $this->assertFalse(
                ReservationStateMachine::isTerminal($status),
                "{$status} must not be terminal",
            );
        }
    }

    public function test_unknown_status_is_not_terminal(): void
    {
        $this->assertFalse(ReservationStateMachine::isTerminal('not_a_status'));
    }

    // ---------------------------------------------------------------------
    // 4. The complete approved transition map.
    // ---------------------------------------------------------------------

    public function test_transition_map_matches_the_approved_table_exactly(): void
    {
        $this->assertSame($this->approvedMap(), ReservationStateMachine::TRANSITIONS);
    }

    public function test_every_status_constant_is_a_node_in_the_map(): void
    {
        $constants = [
            Reservation::STATUS_PENDING,
            Reservation::STATUS_DEPOSIT_HELD,
            Reservation::STATUS_VERIFIED,
            Reservation::STATUS_CHECKED_IN,
            Reservation::STATUS_IN_STAY,
            Reservation::STATUS_CHECKOUT_IN_PROGRESS,
            Reservation::STATUS_CHECKOUT_BLOCKED,
            Reservation::STATUS_CHECKED_OUT,
            Reservation::STATUS_INVOICED,
            Reservation::STATUS_CANCELLED,
        ];

        sort($constants);
        $nodes = ReservationStateMachine::statuses();
        sort($nodes);

        $this->assertSame($constants, $nodes);
    }

    public function test_no_transition_targets_an_unknown_status(): void
    {
        $known = ReservationStateMachine::statuses();

        foreach (ReservationStateMachine::TRANSITIONS as $from => $targets) {
            foreach ($targets as $target) {
                $this->assertContains($target, $known, "{$from} -> {$target} targets an unknown status");
                $this->assertNotSame($from, $target, "{$from} must not transition to itself");
            }
        }
    }

    public function test_exhaustive_can_transition_matches_the_approved_map(): void
    {
        $map = $this->approvedMap();

        foreach (ReservationStateMachine::statuses() as $from) {
            foreach (ReservationStateMachine::statuses() as $to) {
                $expected = in_array($to, $map[$from], true);

                $this->assertSame(
                    $expected,
                    ReservationStateMachine::canTransition($from, $to),
                    "canTransition({$from}, {$to}) should be ".($expected ? 'true' : 'false'),
                );
            }
        }
    }

    public function test_initial_status_is_pending(): void
    {
        $this->assertSame(Reservation::STATUS_PENDING, ReservationStateMachine::INITIAL_STATUS);
    }

    // ---------------------------------------------------------------------
    // 5. Blocking status classification (approved Phase 3D list — Phase 4A
    //    only re-exposes it; it must stay in lock-step with the Model).
    // ---------------------------------------------------------------------

    public function test_every_status_except_cancelled_is_blocking(): void
    {
        foreach (ReservationStateMachine::statuses() as $status) {
            if ($status === Reservation::STATUS_CANCELLED) {
                $this->assertFalse(ReservationStateMachine::isBlocking($status));

                continue;
            }

            $this->assertTrue(
                ReservationStateMachine::isBlocking($status),
                "{$status} must remain a blocking inventory status",
            );
        }
    }

    public function test_is_blocking_delegates_to_the_model_classification(): void
    {
        foreach (ReservationStateMachine::statuses() as $status) {
            $this->assertSame(
                in_array($status, Reservation::BLOCKING_STATUSES, true),
                ReservationStateMachine::isBlocking($status),
            );
        }
    }
}
