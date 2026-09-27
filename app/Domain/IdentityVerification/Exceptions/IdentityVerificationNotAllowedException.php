<?php

namespace App\Domain\IdentityVerification\Exceptions;

use RuntimeException;

/**
 * Thrown when identity verification is initiated for a Reservation that is
 * not in a state that permits it (Phase 0 §8/§20 — verification follows the
 * deposit hold: the Reservation must be DEPOSIT_HELD).
 *
 * The message names only the business fact involved — no internal detail —
 * mirroring PaymentHoldNotAllowedException.
 */
class IdentityVerificationNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reservationStatus)
    {
        parent::__construct(
            "Identity verification cannot be started while the reservation is '{$reservationStatus}'."
        );
    }
}
