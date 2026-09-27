<?php

namespace App\Domain\Reservation\Exceptions;

use RuntimeException;

/**
 * Thrown when staff try to assign (or move) the physical room of a
 * Reservation that is no longer occupying inventory as a live stay —
 * cancelled, or already in/after checkout. Mirrors
 * ReservationExtensionNotAllowedException's shape.
 */
class ReservationRoomAssignmentNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reservationStatus)
    {
        parent::__construct(
            "A room cannot be assigned while the reservation is '{$reservationStatus}'."
        );
    }
}
