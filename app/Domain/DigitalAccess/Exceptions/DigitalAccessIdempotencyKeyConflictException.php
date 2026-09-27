<?php

namespace App\Domain\DigitalAccess\Exceptions;

use RuntimeException;

/**
 * Thrown when an idempotency key supplied for a check-in / issuance was
 * already used for a different logical operation (a different reservation).
 * Mirrors IdempotencyKeyConflictException in the Payment domain.
 */
class DigitalAccessIdempotencyKeyConflictException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The digital access idempotency key was already used for a {$reason}.");
    }
}
