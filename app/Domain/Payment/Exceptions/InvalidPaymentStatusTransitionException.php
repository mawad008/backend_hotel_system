<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when a Payment status change is attempted outside the approved
 * transition table (Phase 0 §9, Phase 5A plan §10 — "explicit allowed
 * transitions only; any other transition attempt is rejected"). The
 * message names only the two business statuses involved — no internal
 * implementation detail — mirroring InvalidReservationStatusTransitionException.
 */
class InvalidPaymentStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct("Cannot transition a payment from '{$from}' to '{$to}'.");
    }
}
