<?php

namespace App\Domain\Payment\StateMachine;

use App\Domain\Payment\Exceptions\InvalidPaymentStatusTransitionException;
use App\Domain\Payment\Models\Payment;

/**
 * Phase 5A: the single authoritative source of truth for which Payment
 * status transitions are allowed (Phase 0 §9, guardrail #7). Structural
 * transition rules live here and nowhere else — not in a Service, the
 * Model, a Policy, a Form Request, or a test. Mirrors the stateless,
 * non-instantiable ReservationStateMachine.
 *
 * The map below is exactly the approved Phase 5A transition table
 * (Phase 5A instructions §10):
 *
 *   NOT_STARTED                 -> HOLD_REQUESTED
 *   HOLD_REQUESTED              -> HOLD_ACTIVE, HOLD_FAILED, EXPIRED, CANCELLED
 *   HOLD_ACTIVE                 -> CAPTURE_REQUESTED, EXPIRED, CANCELLED
 *   HOLD_FAILED                 -> HOLD_REQUESTED
 *   CAPTURE_REQUESTED           -> CAPTURED, CAPTURE_FAILED
 *   CAPTURE_FAILED              -> CAPTURE_REQUESTED
 *   CAPTURED                    -> FINAL_SETTLEMENT_REQUESTED
 *   FINAL_SETTLEMENT_REQUESTED  -> SETTLED, SETTLEMENT_FAILED
 *   SETTLEMENT_FAILED           -> FINAL_SETTLEMENT_REQUESTED
 *   REFUND_REQUESTED            -> REFUNDED, REFUND_FAILED
 *
 * REFUND_REQUESTED has no inbound edge: refund execution (and therefore
 * its entry point) is deferred, so no transition invents its way in
 * (Phase 5A instructions §10). SETTLED, CANCELLED, EXPIRED, REFUNDED and
 * REFUND_FAILED are terminal (empty transition list). This map is
 * independent of PaymentTransaction status, webhook processing status, and
 * Reservation status.
 */
final class PaymentStateMachine
{
    /**
     * The status every Payment starts in (Phase 0 §9).
     */
    public const INITIAL_STATUS = Payment::STATUS_NOT_STARTED;

    /**
     * The complete approved transition table. Every key is a status; its
     * value is the exhaustive list of statuses it may move to. A status
     * mapped to an empty list is terminal by definition.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
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

    private function __construct()
    {
        // Pure static lookup — never instantiated.
    }

    /**
     * Every status known to the state machine, in declared order.
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * The statuses $from may transition to. Unknown statuses yield an empty
     * list (treated as "no transition allowed"), never an error.
     *
     * @return list<string>
     */
    public static function allowedTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /**
     * Whether moving directly from $from to $to is an approved transition.
     * A status is never allowed to transition to itself.
     */
    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedTransitions($from), true);
    }

    /**
     * A terminal status has no outgoing transitions (SETTLED, CANCELLED,
     * EXPIRED, REFUNDED, REFUND_FAILED). An unknown status is not
     * considered terminal.
     */
    public static function isTerminal(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS)
            && self::TRANSITIONS[$status] === [];
    }

    /**
     * Guard used by later phases before persisting a status change.
     *
     * @throws InvalidPaymentStatusTransitionException if $from -> $to is
     *                                                 not in the approved transition table.
     */
    public static function assertCanTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidPaymentStatusTransitionException($from, $to);
        }
    }
}
