<?php

namespace App\Domain\Inventory\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when a Room status change is attempted outside the Phase 2B
 * allowed operational transitions (available <-> under_maintenance).
 * `booked` is reserved for the future Reservations domain and can never
 * be an allowed source or target here.
 */
class InvalidRoomStatusTransitionException extends RuntimeException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(ErrorText::message('room_status_transition', ['from' => ErrorText::status($from), 'to' => ErrorText::status($to)]));
    }
}
