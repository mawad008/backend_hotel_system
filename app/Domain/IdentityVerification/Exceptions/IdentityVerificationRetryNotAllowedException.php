<?php

namespace App\Domain\IdentityVerification\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when a verification retry is requested but the configured retry
 * limit (config('verification.max_retries')) has been reached for the
 * session. State-driven and configuration-driven — never an invented count.
 */
class IdentityVerificationRetryNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $currentStatus)
    {
        parent::__construct(
            ErrorText::message('identity_retry_limit')
        );
    }
}
