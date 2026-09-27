<?php

namespace App\Domain\GuestAccess\Exceptions;

use RuntimeException;

/**
 * The challenge existed but its TTL elapsed before a correct code arrived.
 * Rendered as 422; the client requests a fresh code.
 */
class OtpChallengeExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('api.guest_auth.otp_challenge_expired'));
    }
}
