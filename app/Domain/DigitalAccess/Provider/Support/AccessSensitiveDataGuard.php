<?php

namespace App\Domain\DigitalAccess\Provider\Support;

use InvalidArgumentException;

/**
 * Phase 7 — a single place that enforces the "no access secrets in
 * metadata" rule at the provider boundary (Phase 0 §17).
 *
 * The credential (PIN / smart-lock token) travels ONLY in the dedicated
 * AccessResult::$credential field and is persisted only in the encrypted
 * AccessGrant::$credential column. Any metadata that reaches a request DTO
 * or comes back as provider context is checked against this denylist and
 * rejected outright, so a secret can never leak into an AccessGrant's
 * `metadata` json or an audit row.
 */
final class AccessSensitiveDataGuard
{
    /**
     * Substrings that must never appear in a metadata key. Matched
     * case-insensitively against the normalized key.
     *
     * @var list<string>
     */
    public const DENYLISTED_KEY_FRAGMENTS = [
        'pin_code',
        'pincode',
        'passcode',
        'access_code',
        'door_code',
        'unlock_code',
        'credential',
        'secret',
        'password',
        'private_key',
        'token',
        'otp',
    ];

    private function __construct()
    {
        // Static helper — never instantiated.
    }

    /**
     * Metadata guard: keys must not look like a secret and values must be
     * scalar or null (metadata is flat by contract).
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
                    "Digital access {$context} metadata values must be scalar or null."
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
                    "The digital access provider does not accept secret data in metadata ({$context})."
                );
            }
        }
    }
}
