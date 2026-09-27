<?php

namespace App\Domain\Reservation\Exceptions;

use RuntimeException;

/**
 * Thrown when a Reservation is asked to reference a specific Room that does
 * not belong to the same hotel as the reservation's Room Type (approved
 * Hybrid model, §6.2 rule 7).
 */
class RoomHotelMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The selected room does not belong to this hotel.');
    }
}
