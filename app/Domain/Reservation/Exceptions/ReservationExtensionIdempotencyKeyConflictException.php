<?php

namespace App\Domain\Reservation\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when an `Idempotency-Key` already recorded for a reservation
 * extension is replayed with a different reservation or a different
 * `new_check_out` — the same key may only ever replay the exact same
 * extension, mirroring Payment's IdempotencyKeyConflictException.
 */
class ReservationExtensionIdempotencyKeyConflictException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(
            ErrorText::message('extension_idempotency_conflict', ['reason' => $reason])
        );
    }
}
