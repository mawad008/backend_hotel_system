<?php

namespace App\Domain\GuestAccess\Exceptions;

use RuntimeException;

/**
 * The `challenge_id` + `phone` pair does not resolve to an active challenge
 * (never issued, wrong phone, or already consumed). Rendered as 422 with a
 * fixed safe message — it never distinguishes the reasons.
 */
class OtpChallengeNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('api.guest_auth.otp_challenge_invalid'));
    }
}
