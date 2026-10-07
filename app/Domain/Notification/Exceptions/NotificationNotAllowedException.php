<?php

namespace App\Domain\Notification\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Phase 11 — a notification operation that a business rule forbids (e.g.
 * marking a non-in_app row read, or dispatching for a reservation with no
 * recipient). Fixed, safe machine strings only — no secret, no provider
 * payload, no SQLSTATE. Rendered as HTTP 422, consistent with every other
 * domain exception in the project (there is no 409 convention).
 */
class NotificationNotAllowedException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }

    public static function notReadable(): self
    {
        return new self(ErrorText::message('notification_not_readable'));
    }

    public static function recipientUnresolved(): self
    {
        return new self(ErrorText::message('notification_recipient_unresolved'));
    }

    public static function deliveryRaceUnresolved(): self
    {
        return new self(ErrorText::message('notification_race_unresolved'));
    }
}
