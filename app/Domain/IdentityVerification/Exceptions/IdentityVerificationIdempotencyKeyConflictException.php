<?php

namespace App\Domain\IdentityVerification\Exceptions;

use RuntimeException;

/**
 * Thrown when an idempotency key supplied for a selfie/match submission was
 * already used for a different logical operation (a different session).
 * Mirrors IdempotencyKeyConflictException in the Payment domain.
 */
class IdentityVerificationIdempotencyKeyConflictException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The identity verification idempotency key was already used for a {$reason}.");
    }
}
