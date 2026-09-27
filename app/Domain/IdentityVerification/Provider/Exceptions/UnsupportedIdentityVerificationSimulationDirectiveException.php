<?php

namespace App\Domain\IdentityVerification\Provider\Exceptions;

use RuntimeException;

/**
 * Phase 6 — thrown when an identity verification simulation directive
 * (config default, or a controlled local/testing header) is not one of the
 * approved values. Fails loudly rather than silently defaulting.
 */
class UnsupportedIdentityVerificationSimulationDirectiveException extends RuntimeException
{
    public function __construct(public readonly string $value)
    {
        parent::__construct("Unsupported identity verification simulation directive: '{$value}'.");
    }
}
