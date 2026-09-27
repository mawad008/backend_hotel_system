<?php

namespace App\Domain\GuestAccess\Exceptions;

use RuntimeException;

/**
 * A resend was asked for before the cooldown elapsed, or the per-challenge
 * resend ceiling was reached. Rendered as 422.
 */
class OtpResendCooldownException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct(__('api.guest_auth.otp_resend_cooldown'));
    }
}
