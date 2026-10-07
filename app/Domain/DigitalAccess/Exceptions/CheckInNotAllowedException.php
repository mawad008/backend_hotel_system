<?php

namespace App\Domain\DigitalAccess\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when check-in is attempted for a Reservation that is not in a state
 * that permits it (Phase 0 §8 — check-in follows identity verification: the
 * Reservation must be VERIFIED).
 *
 * The message names only the business fact involved — no internal detail —
 * mirroring PaymentHoldNotAllowedException / IdentityVerificationNotAllowedException.
 */
class CheckInNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reservationStatus)
    {
        parent::__construct(
            ErrorText::message('check_in_not_allowed', ['status' => ErrorText::status($reservationStatus)])
        );
    }
}
