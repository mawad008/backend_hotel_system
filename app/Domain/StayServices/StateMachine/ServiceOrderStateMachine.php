<?php

namespace App\Domain\StayServices\StateMachine;

use App\Domain\StayServices\Exceptions\InvalidServiceOrderStatusTransitionException;
use App\Domain\StayServices\Models\ServiceOrder;

/**
 * Phase 8 — the single authoritative source of truth for which ServiceOrder
 * status transitions are allowed (Phase 0 guardrail #7). Structural
 * transition rules live here and nowhere else — not in a Service, the
 * Model, a Policy, a Form Request, or a test. Mirrors the stateless,
 * non-instantiable PaymentStateMachine / ReservationStateMachine.
 *
 * ── Technical decision (documented, not invented) ──
 * The approved baseline defines NO explicit service-order lifecycle. The
 * smallest practical one is used:
 *
 *   requested -> confirmed, cancelled
 *   confirmed -> fulfilled, cancelled
 *   fulfilled -> (terminal)
 *   cancelled -> (terminal)
 *
 * A folio charge is created when the order enters `confirmed` (the point it
 * becomes financially chargeable). `fulfilled` is a pure fulfilment marker
 * with no financial effect. Cancelling a `confirmed` order voids its folio
 * charge (never a negative charge — reversal semantics are deferred to the
 * checkout/refund phase).
 */
final class ServiceOrderStateMachine
{
    public const INITIAL_STATUS = ServiceOrder::STATUS_REQUESTED;

    /**
     * The complete approved transition table. A status mapped to an empty
     * list is terminal by definition.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        ServiceOrder::STATUS_REQUESTED => [
            ServiceOrder::STATUS_CONFIRMED,
            ServiceOrder::STATUS_CANCELLED,
        ],
        ServiceOrder::STATUS_CONFIRMED => [
            ServiceOrder::STATUS_FULFILLED,
            ServiceOrder::STATUS_CANCELLED,
        ],
        ServiceOrder::STATUS_FULFILLED => [],
        ServiceOrder::STATUS_CANCELLED => [],
    ];

    private function __construct()
    {
        // Pure static lookup — never instantiated.
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * @return list<string>
     */
    public static function allowedTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedTransitions($from), true);
    }

    public static function isTerminal(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS)
            && self::TRANSITIONS[$status] === [];
    }

    /**
     * @throws InvalidServiceOrderStatusTransitionException if $from -> $to is
     *                                                      not an approved transition.
     */
    public static function assertCanTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidServiceOrderStatusTransitionException($from, $to);
        }
    }
}
