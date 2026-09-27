<?php

namespace App\Domain\IdentityVerification\Provider\Exceptions;

use RuntimeException;

/**
 * Phase 6 — thrown when config('verification.provider') names a provider
 * that has no registered implementation. Mirrors
 * UnsupportedPaymentProviderException: the binding never silently falls back
 * to the dummy provider, and the message never echoes a secret.
 */
class UnsupportedIdentityVerificationProviderException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("Unsupported identity verification provider configured: '{$provider}'.");
    }
}
