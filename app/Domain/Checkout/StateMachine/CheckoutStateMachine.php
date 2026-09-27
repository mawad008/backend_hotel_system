<?php

namespace App\Domain\Checkout\StateMachine;

use App\Domain\Checkout\Exceptions\InvalidCheckoutStatusTransitionException;
use App\Domain\Checkout\Models\Checkout;

/**
 * Phase 9A — the single authoritative source of truth for which Checkout
 * status transitions are allowed (Phase 0 §12, guardrail #7). Structural
 * rules live here and nowhere else — mirrors ReservationStateMachine /
 * PaymentStateMachine / ServiceOrderStateMachine.
 *
 *   in_progress         -> awaiting_settlement, settlement_failed, completed
 *   awaiting_settlement -> in_progress, settlement_failed, completed
 *   settlement_failed   -> in_progress, awaiting_settlement, completed
 *   completed           -> (terminal)
 *
 * The non-completed statuses form a small retry cluster: a failed / pending
 * final settlement can be retried, which re-opens the checkout to
 * `in_progress` before the next attempt. `completed` is terminal — once the
 * reservation is INVOICED and the invoice issued, checkout never re-opens.
 */
final class CheckoutStateMachine
{
    public const INITIAL_STATUS = Checkout::STATUS_IN_PROGRESS;

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        Checkout::STATUS_IN_PROGRESS => [
            Checkout::STATUS_AWAITING_SETTLEMENT,
            Checkout::STATUS_SETTLEMENT_FAILED,
            Checkout::STATUS_COMPLETED,
        ],
        Checkout::STATUS_AWAITING_SETTLEMENT => [
            Checkout::STATUS_IN_PROGRESS,
            Checkout::STATUS_SETTLEMENT_FAILED,
            Checkout::STATUS_COMPLETED,
        ],
        Checkout::STATUS_SETTLEMENT_FAILED => [
            Checkout::STATUS_IN_PROGRESS,
            Checkout::STATUS_AWAITING_SETTLEMENT,
            Checkout::STATUS_COMPLETED,
        ],
        Checkout::STATUS_COMPLETED => [],
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

    /**
     * Whether moving directly from $from to $to is an approved transition.
     * A status is never allowed to transition to itself (the service skips
     * the call when the status is unchanged).
     */
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
     * @throws InvalidCheckoutStatusTransitionException
     */
    public static function assertCanTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidCheckoutStatusTransitionException($from, $to);
        }
    }
}
