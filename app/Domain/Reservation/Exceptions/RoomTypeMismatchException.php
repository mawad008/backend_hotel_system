<?php

namespace App\Domain\Reservation\Exceptions;

use RuntimeException;

/**
 * Thrown when a Reservation is asked to reference a specific Room that does
 * not belong to the selected Room Type (approved Hybrid model, §6.2 rule 7:
 * an assigned room must belong to the reservation's own Room Type).
 */
class RoomTypeMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The selected room does not belong to the selected room type.');
    }
}
