<?php

namespace App\Domain\Reservation\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when a stay extension is requested for a Reservation that is not
 * `checked_in` / `in_stay` — Extend Stay only applies to a guest currently
 * occupying the room. Mirrors PaymentHoldNotAllowedException's shape.
 */
class ReservationExtensionNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reservationStatus)
    {
        parent::__construct(
            ErrorText::message('extension_not_allowed', ['status' => ErrorText::status($reservationStatus)])
        );
    }
}
