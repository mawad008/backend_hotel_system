<?php

namespace App\Domain\IdentityVerification\StateMachine;

use App\Domain\IdentityVerification\Exceptions\InvalidIdentityVerificationStatusTransitionException;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;

/**
 * Phase 6 — the single authoritative source of truth for which Identity
 * Verification session status transitions are allowed (Phase 0 §10,
 * guardrail #7 — "explicit allowed transitions only; any other transition
 * attempt is rejected server-side").
 *
 * Transition rules live here and nowhere else — not in a Service, the
 * Model, a Policy, a Form Request, or a test. The workflow service drives
 * status changes through {@see self::assertCanTransition()}.
 *
 * Stateless and non-instantiable — a pure lookup over class constants,
 * matching PaymentStateMachine / ReservationStateMachine.
 *
 * The map is exactly the approved Phase 0 §10 diagram:
 *
 *   NOT_STARTED           -> DOCUMENT_UPLOADED
 *   DOCUMENT_UPLOADED     -> SELFIE_CAPTURED
 *   SELFIE_CAPTURED       -> MATCHING_IN_PROGRESS
 *   MATCHING_IN_PROGRESS  -> AUTO_APPROVED | PENDING_MANUAL_REVIEW | RETRY_ALLOWED
 *   RETRY_ALLOWED         -> DOCUMENT_UPLOADED (new attempt)
 *                          | PENDING_MANUAL_REVIEW (retry limit reached / unconfigured — §10
 *                            "routed to PENDING_MANUAL_REVIEW ... rather than further auto-retry")
 *   PENDING_MANUAL_REVIEW -> STAFF_APPROVED | STAFF_REJECTED
 *   STAFF_REJECTED        -> DOCUMENT_UPLOADED (new attempt, if under the configured retry limit)
 *
 * AUTO_APPROVED and STAFF_APPROVED are terminal. STAFF_REJECTED is NOT
 * terminal — §10 gives it a retry edge back to DOCUMENT_UPLOADED; whether a
 * retry is permitted is a configuration guard the workflow layer applies,
 * not a structural transition rule. The retry-count limit itself
 * (config('verification.max_retries')) is NEVER encoded here.
 */
final class IdentityVerificationStateMachine
{
    /**
     * The status every session starts in (Phase 0 §10).
     */
    public const INITIAL_STATUS = IdentityVerificationSession::STATUS_NOT_STARTED;

    /**
     * The complete approved transition table. A status mapped to an empty
     * list is terminal by definition.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        IdentityVerificationSession::STATUS_NOT_STARTED => [
            IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED,
        ],
        IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED => [
            IdentityVerificationSession::STATUS_SELFIE_CAPTURED,
        ],
        IdentityVerificationSession::STATUS_SELFIE_CAPTURED => [
            IdentityVerificationSession::STATUS_MATCHING_IN_PROGRESS,
        ],
        IdentityVerificationSession::STATUS_MATCHING_IN_PROGRESS => [
            IdentityVerificationSession::STATUS_AUTO_APPROVED,
            IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
            IdentityVerificationSession::STATUS_RETRY_ALLOWED,
        ],
        IdentityVerificationSession::STATUS_RETRY_ALLOWED => [
            IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED,
            IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
        ],
        IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW => [
            IdentityVerificationSession::STATUS_STAFF_APPROVED,
            IdentityVerificationSession::STATUS_STAFF_REJECTED,
        ],
        IdentityVerificationSession::STATUS_STAFF_REJECTED => [
            IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED,
        ],
        IdentityVerificationSession::STATUS_AUTO_APPROVED => [],
        IdentityVerificationSession::STATUS_STAFF_APPROVED => [],
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
     * A terminal status has no outgoing transitions (AUTO_APPROVED,
     * STAFF_APPROVED). An unknown status is not considered terminal.
     */
    public static function isTerminal(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS)
            && self::TRANSITIONS[$status] === [];
    }

    /**
     * Guard used by the workflow layer before persisting a status change.
     *
     * @throws InvalidIdentityVerificationStatusTransitionException if
     *                                                              $from -> $to is not in the approved transition table.
     */
    public static function assertCanTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidIdentityVerificationStatusTransitionException($from, $to);
        }
    }
}
