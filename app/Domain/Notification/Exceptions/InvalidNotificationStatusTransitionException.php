<?php

namespace App\Domain\Notification\Exceptions;

use App\Domain\Notification\Enums\NotificationStatus;
use RuntimeException;

/**
 * Phase 11 — a notification delivery status change that is not in the
 * approved NotificationDeliveryStateMachine transition table.
 *
 * The message is a fixed, safe machine string (no secret, no provider
 * payload). Rendered as HTTP 422, like every other domain state-machine
 * exception in the project.
 */
class InvalidNotificationStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct("Notification delivery cannot transition from {$from} to {$to}.");
    }

    public static function between(NotificationStatus $from, NotificationStatus $to): self
    {
        return new self($from->value, $to->value);
    }
}
