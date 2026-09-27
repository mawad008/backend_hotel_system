<?php

namespace App\Domain\IdentityVerification\Exceptions;

use RuntimeException;

/**
 * Thrown when an Identity Verification session status change is attempted
 * outside the approved transition table (Phase 0 §10 — "explicit allowed
 * transitions only; any other transition attempt is rejected"). The message
 * names only the two business statuses involved — no internal detail —
 * mirroring InvalidReservationStatusTransitionException.
 */
class InvalidIdentityVerificationStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct("Cannot transition an identity verification from '{$from}' to '{$to}'.");
    }
}
