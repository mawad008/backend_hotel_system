<?php

namespace App\Domain\Reservation\StateMachine;

use App\Domain\Reservation\Exceptions\InvalidReservationStatusTransitionException;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 4A foundation: the single authoritative source of truth for which
 * Reservation status transitions are allowed (Phase 0 §8, guardrail #7 —
 * "explicit allowed transitions only; any other transition attempt is
 * rejected server-side").
 *
 * Transition rules live here and nowhere else — not in the Controller, the
 * Model, a Policy, a Form Request, or a test. Later phases (4B+) drive the
 * actual status changes through {@see self::assertCanTransition()}; this
 * phase only establishes and proves the map.
 *
 * This class is intentionally stateless and non-instantiable: it is a pure
 * lookup over class constants, matching the static-map style already used by
 * RoomService::ALLOWED_TRANSITIONS.
 */
final class ReservationStateMachine
{
    /**
     * The status every Reservation starts in (Phase 0 §6.3 — the inventory
     * lock is applied the instant PENDING is entered). Reservation creation
     * still writes this literally; exposed here so the "initial state" fact
     * has one home.
     */
    public const INITIAL_STATUS = Reservation::STATUS_PENDING;

    /**
     * The complete approved transition table (Phase 0 §8). Every key is a
     * status; its value is the exhaustive list of statuses it may move to.
     *
     * A status mapped to an empty list is terminal by definition. CANCELLED
     * and INVOICED are terminal. CHECKOUT_BLOCKED is deliberately terminal
     * *in this phase*: Phase 0 §8 marks it "(staff-resolved)" but does not
     * define its outgoing arrow, and Phase 4A does not invent one — see the
     * Phase 4A report's open-workflow item.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
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
     * A terminal status has no outgoing transitions (CANCELLED, INVOICED,
     * and — for Phase 4A only — CHECKOUT_BLOCKED). An unknown status is not
     * considered terminal.
     */
    public static function isTerminal(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS)
            && self::TRANSITIONS[$status] === [];
    }

    /**
     * Whether a Reservation in $status holds a claim on inventory. Delegates
     * to the approved Phase 3D list on the Model — Phase 4A does not own or
     * duplicate that classification, only re-exposes it alongside the rest
     * of the status API.
     */
    public static function isBlocking(string $status): bool
    {
        return in_array($status, Reservation::BLOCKING_STATUSES, true);
    }

    /**
     * Guard used by later phases before persisting a status change.
     *
     * @throws InvalidReservationStatusTransitionException if $from → $to is
     *                                                     not in the approved transition table.
     */
    public static function assertCanTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidReservationStatusTransitionException($from, $to);
        }
    }
}
