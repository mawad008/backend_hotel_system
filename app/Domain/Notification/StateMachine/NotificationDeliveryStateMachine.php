<?php

namespace App\Domain\Notification\StateMachine;

use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Exceptions\InvalidNotificationStatusTransitionException;

/**
 * Phase 11 — the single authoritative source of truth for which notification
 * delivery status transitions are allowed (Phase 0 guardrail #7 — "explicit
 * allowed transitions only; any other transition attempt is rejected
 * server-side").
 *
 * Transition rules live here and nowhere else — not in the Model, a Service,
 * a Policy, or a test. The workflow service drives status changes through
 * {@see self::assertCanTransition()}.
 *
 * Stateless and non-instantiable — a pure lookup, matching
 * PaymentStateMachine / DigitalAccessStateMachine.
 *
 * Full map:
 *   PENDING  -> SENDING
 *   SENDING  -> SENT, FAILED
 *   FAILED   -> SENDING            (safe retry)
 *   SENT     -> (terminal)
 */
final class NotificationDeliveryStateMachine
{
    public const INITIAL_STATUS = NotificationStatus::Pending;

    /**
     * The complete approved transition table. A status mapped to an empty
     * list is terminal.
     *
     * @var array<string, list<NotificationStatus>>
     */
    private const TRANSITIONS = [
        NotificationStatus::Pending->value => [
            NotificationStatus::Sending,
        ],
        NotificationStatus::Sending->value => [
            NotificationStatus::Sent,
            NotificationStatus::Failed,
        ],
        NotificationStatus::Failed->value => [
            NotificationStatus::Sending,
        ],
        NotificationStatus::Sent->value => [],
    ];

    private function __construct()
    {
        // Pure static lookup — never instantiated.
    }

    /**
     * @return list<NotificationStatus>
     */
    public static function allowedTransitions(NotificationStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }

    public static function canTransition(NotificationStatus $from, NotificationStatus $to): bool
    {
        return in_array($to, self::allowedTransitions($from), true);
    }

    public static function isTerminal(NotificationStatus $status): bool
    {
        return array_key_exists($status->value, self::TRANSITIONS)
            && self::TRANSITIONS[$status->value] === [];
    }

    /**
     * @throws InvalidNotificationStatusTransitionException
     */
    public static function assertCanTransition(NotificationStatus $from, NotificationStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw InvalidNotificationStatusTransitionException::between($from, $to);
        }
    }
}
