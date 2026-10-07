<?php

namespace App\Domain\IdentityVerification\Exceptions;

use App\Support\ErrorText;
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
        parent::__construct(ErrorText::message('identity_idempotency_conflict', ['reason' => $reason]));
    }
}
