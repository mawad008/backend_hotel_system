<?php

namespace App\Domain\DigitalAccess\StateMachine;

use App\Domain\DigitalAccess\Exceptions\InvalidDigitalAccessStatusTransitionException;
use App\Domain\DigitalAccess\Models\AccessGrant;

/**
 * Phase 7 — the single authoritative source of truth for which Digital
 * Access status transitions are allowed (Phase 0 §11, guardrail #7 —
 * "explicit allowed transitions only; any other transition attempt is
 * rejected server-side").
 *
 * Transition rules live here and nowhere else — not in a Service, the
 * Model, a Policy, a Form Request, or a test. The workflow service drives
 * status changes through {@see self::assertCanTransition()}.
 *
 * Stateless and non-instantiable — a pure lookup over class constants,
 * matching PaymentStateMachine / ReservationStateMachine /
 * IdentityVerificationStateMachine.
 *
 * The business-visible lifecycle is exactly Phase 0 §11:
 *
 *   NOT_ISSUED ──(eligibility met)──► ISSUED(=ACTIVE) ──► EXPIRED | REVOKED
 *
 * The extra nodes below are ARCHITECTURE-DERIVED, not new business states:
 *   - ISSUE_REQUESTED  — the staged provider-call pending state (mirrors
 *                        Payment HOLD_REQUESTED, Identity MATCHING_IN_PROGRESS).
 *   - FAILED           — a recoverable issuance failure (§15 simulates
 *                        "failure"; mirrors Payment HOLD_FAILED). Retrying
 *                        check-in re-requests issuance.
 *   - REVOKE_REQUESTED — the staged revoke-call pending state.
 *
 * Full map:
 *   NOT_ISSUED       -> ISSUE_REQUESTED
 *   ISSUE_REQUESTED  -> ACTIVE, FAILED
 *   FAILED           -> ISSUE_REQUESTED
 *   ACTIVE           -> REVOKE_REQUESTED, EXPIRED
 *   REVOKE_REQUESTED -> REVOKED
 *   EXPIRED / REVOKED -> (terminal)
 */
final class DigitalAccessStateMachine
{
    /**
     * The status every grant starts in (Phase 0 §11).
     */
    public const INITIAL_STATUS = AccessGrant::STATUS_NOT_ISSUED;

    /**
     * The complete approved transition table. A status mapped to an empty
     * list is terminal by definition.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        AccessGrant::STATUS_NOT_ISSUED => [
            AccessGrant::STATUS_ISSUE_REQUESTED,
        ],
        AccessGrant::STATUS_ISSUE_REQUESTED => [
            AccessGrant::STATUS_ACTIVE,
            AccessGrant::STATUS_FAILED,
        ],
        AccessGrant::STATUS_FAILED => [
            AccessGrant::STATUS_ISSUE_REQUESTED,
        ],
        AccessGrant::STATUS_ACTIVE => [
            AccessGrant::STATUS_REVOKE_REQUESTED,
            AccessGrant::STATUS_EXPIRED,
        ],
        AccessGrant::STATUS_REVOKE_REQUESTED => [
            AccessGrant::STATUS_REVOKED,
        ],
        AccessGrant::STATUS_REVOKED => [],
        AccessGrant::STATUS_EXPIRED => [],
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
     * list, never an error.
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
     * A terminal status has no outgoing transitions (EXPIRED, REVOKED). An
     * unknown status is not considered terminal.
     */
    public static function isTerminal(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS)
            && self::TRANSITIONS[$status] === [];
    }

    /**
     * Guard used by the workflow layer before persisting a status change.
     *
     * @throws InvalidDigitalAccessStatusTransitionException if $from -> $to
     *                                                       is not in the approved transition table.
     */
    public static function assertCanTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidDigitalAccessStatusTransitionException($from, $to);
        }
    }
}
