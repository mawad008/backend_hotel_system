<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * Thrown when a Room references (or is asked to reference) a Room Type
 * belonging to a different hotel than the Room itself — a violation of
 * the approved Hybrid model's hotel-consistency invariant
 * (rooms.hotel_id === room_types.hotel_id).
 */
class RoomTypeHotelMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The selected room type does not belong to this hotel.');
    }
}
