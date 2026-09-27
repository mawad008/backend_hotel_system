<?php

namespace App\Domain\DigitalAccess\Exceptions;

use RuntimeException;

/**
 * Thrown when a digital access status change is attempted outside the
 * approved transition table (Phase 0 §11 — "explicit allowed transitions
 * only"). The message names only the two business statuses involved — no
 * internal detail — mirroring InvalidReservationStatusTransitionException.
 */
class InvalidDigitalAccessStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct("Cannot transition digital access from '{$from}' to '{$to}'.");
    }
}
