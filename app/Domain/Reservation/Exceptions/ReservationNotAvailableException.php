<?php

namespace App\Domain\Reservation\Exceptions;

use RuntimeException;

/**
 * Thrown when the requested date range is not available — either a
 * specific Room already has an overlapping blocking reservation, or the
 * Room Type has no remaining physical-room capacity for the range
 * (approved Phase 3D availability rule).
 */
class ReservationNotAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The requested dates are not available.');
    }
}
