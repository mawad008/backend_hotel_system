<?php

namespace App\Domain\IdentityVerification\Provider\Support;

use InvalidArgumentException;

/**
 * Phase 6 — a single place that enforces the "no sensitive identity data"
 * rule at the provider boundary (Phase 0 §17, R38-R42).
 *
 * The provider simulates an external KYC/biometric vendor — it never
 * receives a raw document image, a document number, an MRZ line, a date of
 * birth, or any credential. Any metadata that reaches a request DTO is
 * checked against this denylist and rejected outright, so such data can
 * never be stored in an attempt's `metadata` by a later step.
 */
final class IdentitySensitiveDataGuard
{
    /**
     * Substrings that must never appear in a metadata key. Matched
     * case-insensitively against the normalized key.
     *
     * @var list<string>
     */
    public const DENYLISTED_KEY_FRAGMENTS = [
        'document_number',
        'doc_number',
        'docnumber',
        'document_no',
        'id_number',
        'idnumber',
        'national_id',
        'passport_number',
        'passport_no',
        'license_number',
        'ssn',
        'mrz',
        'date_of_birth',
        'birth_date',
        'dob',
        'selfie',
        'photo',
        'image',
        'picture',
        'file',
        'base64',
        'raw_payload',
        'secret',
        'password',
        'private_key',
        'signature',
        'token',
    ];

    private function __construct()
    {
        // Static helper — never instantiated.
    }

    /**
     * Metadata guard: keys must not look like sensitive identity data and
     * values must be scalar or null (metadata is flat by contract).
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
                    "Identity verification {$context} metadata values must be scalar or null."
                );
            }
        }
    }

    /**
     * Payload guard: no key anywhere in a (possibly nested) structure may
     * look like sensitive identity data.
     *
     * @param  array<mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function assertNoSensitiveKeys(array $data, string $context): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                self::assertKeyIsSafe($key, $context);
            }

            if (is_array($value)) {
                self::assertNoSensitiveKeys($value, $context);
            }
        }
    }

    private static function assertKeyIsSafe(string $key, string $context): void
    {
        $normalized = str_replace(['-', ' '], '_', strtolower($key));

        foreach (self::DENYLISTED_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                throw new InvalidArgumentException(
                    "The identity verification provider does not accept sensitive identity data ({$context})."
                );
            }
        }
    }
}
