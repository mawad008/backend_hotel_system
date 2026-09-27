<?php

namespace App\Domain\GuestAccess\Otp\Contracts;

/**
 * Provider boundary for delivering a one-time code to a phone number.
 * The rest of the app depends only on this; the concrete sender is chosen
 * from config('otp.sender'). Implementations must never log or persist the
 * plaintext code.
 */
interface OtpSenderInterface
{
    public function send(string $phoneE164, string $code): void;
}
