<?php

namespace App\Domain\Payment\Gateway\Support;

use InvalidArgumentException;

/**
 * Phase 5B — a single place that enforces the "no sensitive payment data"
 * rule at the gateway boundary (Phase 0 §17, R38–R42; Phase 5B §5/§16/§20).
 *
 * The gateway simulates an external provider — it never handles a card
 * number, CVV, PIN, expiry, cardholder name, or any credential/secret. Any
 * metadata that reaches a request DTO or comes back through a webhook is
 * checked against this denylist and rejected outright, so such data can
 * never be stored in a PaymentTransaction's metadata or a webhook event's
 * normalized payload by a later phase.
 */
final class SensitiveDataGuard
{
    /**
     * Substrings that must never appear in a metadata / payload key.
     * Matched case-insensitively against the normalized key.
     *
     * @var list<string>
     */
    public const DENYLISTED_KEY_FRAGMENTS = [
        'card_number',
        'cardnumber',
        'card_no',
        'pan',
        'cvv',
        'cvc',
        'cvn',
        'card_pin',
        'pin_block',
        'expiry',
        'exp_month',
        'exp_year',
        'cardholder',
        'card_holder',
        'track_data',
        'magstripe',
        'secret',
        'password',
        'private_key',
    ];

    private function __construct()
    {
        // Static helper — never instantiated.
    }

    /**
     * Metadata guard: keys must not look like sensitive payment data and
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
                    "Payment gateway {$context} metadata values must be scalar or null."
                );
            }
        }
    }

    /**
     * Payload guard: no key anywhere in a (possibly nested) structure may
     * look like sensitive payment data. Used for inbound webhook bodies.
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
                    "The payment gateway does not accept sensitive payment data ({$context})."
                );
            }
        }
    }
}
