<?php

namespace App\Domain\DigitalAccess\Provider\Exceptions;

use RuntimeException;

/**
 * Phase 7 — thrown when config('digital_access.provider') names a provider
 * that has no registered implementation. Mirrors the Payment / Identity
 * Verification bindings: the container never silently falls back to the
 * dummy provider, and the message never echoes a secret.
 */
class UnsupportedDigitalAccessProviderException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("Unsupported digital access provider configured: '{$provider}'.");
    }
}
