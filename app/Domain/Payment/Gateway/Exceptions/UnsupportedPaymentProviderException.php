<?php

namespace App\Domain\Payment\Gateway\Exceptions;

use RuntimeException;

/**
 * Thrown when config('payment.provider') names a provider that has no
 * registered gateway implementation. The application fails clearly rather
 * than silently falling back to the dummy provider (Phase 5B §16).
 *
 * The message names only the configured provider string — never a secret,
 * a payload, or an internal detail.
 */
class UnsupportedPaymentProviderException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("Unsupported payment provider configured: '{$provider}'.");
    }
}
