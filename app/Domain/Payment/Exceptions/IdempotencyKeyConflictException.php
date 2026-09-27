<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when an idempotency key has already been used for a payment
 * operation that does not match the current request — a different
 * Reservation/Payment, or a different operation type (Phase 5C §9, §27).
 *
 * The same key may only ever be replayed for the exact same logical
 * operation; anything else is rejected rather than silently reused.
 */
class IdempotencyKeyConflictException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(
            "This idempotency key has already been used for a different payment operation ({$reason})."
        );
    }
}
