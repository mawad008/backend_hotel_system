<?php

namespace App\Domain\GuestAccess\Support;

/**
 * Minimal E.164 normalization/validation. Not a full libphonenumber — the
 * approved MVP only needs a stable canonical string to key a guest account
 * and an OTP challenge by. FormRequests validate shape; this canonicalizes.
 */
final class PhoneNumber
{
    public const PATTERN = '/^\+[1-9]\d{6,14}$/';

    /**
     * Strips spaces, dashes, parens and dots; turns a leading `00` into `+`.
     * Returns the canonical string (may still be invalid — callers validate).
     */
    public static function normalize(string $raw): string
    {
        $trimmed = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';

        if (str_starts_with($trimmed, '00')) {
            $trimmed = '+'.substr($trimmed, 2);
        }

        return $trimmed;
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, $value);
    }

    /**
     * `+966500000000` -> `+96650****000` — for logs. Never log the full number.
     */
    public static function mask(string $value): string
    {
        if (strlen($value) <= 7) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 6).str_repeat('*', max(0, strlen($value) - 9)).substr($value, -3);
    }
}
