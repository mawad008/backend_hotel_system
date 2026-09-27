<?php

namespace App\Domain\GuestAccess\Otp\Exceptions;

use RuntimeException;

class UnsupportedOtpSenderException extends RuntimeException
{
    public function __construct(string $sender)
    {
        parent::__construct("The configured OTP sender [{$sender}] is not supported.");
    }
}
