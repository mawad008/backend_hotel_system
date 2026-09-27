<?php

namespace App\Domain\Notification\Provider\Support;

use InvalidArgumentException;

/**
 * Phase 11 — a single place that enforces the "no secrets at the notification
 * provider boundary" rule (Phase 0 §17: "no secrets in logs, no raw
 * sensitive payload logging").
 *
 * Any context/metadata that reaches a dispatch DTO or comes back as provider
 * context is checked against this denylist and rejected outright, so a
 * secret can never leak into a `notification_events.context` json, an audit
 * row, or a log line. Notification bodies are rendered from vetted
 * localization templates, never from free-form provider text.
 */
final class NotificationSensitiveDataGuard
{
    /**
     * Substrings that must never appear in a context key. Matched
     * case-insensitively against the normalized key.
     *
     * @var list<string>
     */
    public const DENYLISTED_KEY_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'otp',
        'pin_code',
        'pincode',
        'passcode',
        'credential',
        'card_number',
        'card',
        'cvv',
        'cvc',
        'private_key',
        'api_key',
        'apikey',
        'authorization',
        'access_code',
    ];

    private function __construct()
    {
        // Static helper — never instantiated.
    }

    /**
     * Context guard: keys must not look like a secret and values must be
     * scalar or null (context is flat by contract).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function assertClean(array $data, string $context): void
    {
        foreach ($data as $key => $value) {
            self::assertKeyIsSafe((string) $key, $context);

            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException(
                    "Notification {$context} context values must be scalar or null."
                );
            }
        }
    }

    private static function assertKeyIsSafe(string $key, string $context): void
    {
        $normalized = str_replace(['-', ' '], '_', strtolower($key));

        foreach (self::DENYLISTED_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                throw new InvalidArgumentException(
                    "The notification provider does not accept secret data in context ({$context})."
                );
            }
        }
    }
}
