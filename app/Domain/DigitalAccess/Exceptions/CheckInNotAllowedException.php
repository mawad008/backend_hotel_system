<?php

namespace App\Domain\DigitalAccess\Exceptions;

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
            "Check-in cannot proceed while the reservation is '{$reservationStatus}'."
        );
    }
}
