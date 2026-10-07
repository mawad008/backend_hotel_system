<?php

namespace App\Domain\Reservation\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when a Reservation status change is attempted outside the approved
 * Phase 0 §8 transition table (Phase 4A state-machine foundation). The
 * message names only the two business statuses involved — no internal
 * implementation detail — and is surfaced to the API as a 422 with that
 * message verbatim, mirroring InvalidRoomStatusTransitionException.
 */
class InvalidReservationStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct(ErrorText::message('reservation_status_transition', ['from' => ErrorText::status($from), 'to' => ErrorText::status($to)]));
    }
}
