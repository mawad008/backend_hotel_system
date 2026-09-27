<?php

namespace App\Domain\GuestAccess\Otp;

use App\Domain\GuestAccess\Otp\Contracts\OtpSenderInterface;
use App\Domain\GuestAccess\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * The approved dummy provider — no real SMS. It records a single masked
 * dispatch line so an operator can see the flow ran, and, only when
 * `config('otp.fixed_code')` is set (development), echoes the code to the log
 * so the flow is walkable without a device. With no fixed code configured the
 * code is never written anywhere in plaintext.
 */
class DummyOtpSender implements OtpSenderInterface
{
    public function send(string $phoneE164, string $code): void
    {
        $context = ['phone' => PhoneNumber::mask($phoneE164)];

        if (filled(config('otp.fixed_code'))) {
            $context['code'] = $code;
            $context['note'] = 'OTP_FIXED_CODE is set — development only';
        }

        Log::info('guest.otp.dispatched', $context);
    }
}
