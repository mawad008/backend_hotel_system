<?php

namespace App\Domain\IdentityVerification\Exceptions;

use RuntimeException;

/**
 * Thrown when a required-but-genuinely-unresolved business configuration
 * value (Phase 0 §20 items 2/3/5 — confidence thresholds, retry limit,
 * retention period) is needed to proceed and has not been set.
 *
 * The domain FAILS SAFELY here rather than inventing a number. The message
 * names only which configuration key is missing — no value, no secret.
 */
class IdentityVerificationConfigurationMissingException extends RuntimeException
{
    public function __construct(public readonly string $configKey)
    {
        parent::__construct(
            "A required identity verification configuration value is not set: '{$configKey}'."
        );
    }
}
