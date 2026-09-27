<?php

namespace App\Domain\Notification\Provider\Exceptions;

use RuntimeException;

/**
 * Phase 11 — a configured dummy-provider directive string that is not one of
 * the deterministic simulation directives. Fails loudly rather than
 * defaulting silently.
 */
class UnsupportedNotificationSimulationDirectiveException extends RuntimeException
{
    public function __construct(string $value)
    {
        parent::__construct("Unsupported notification simulation directive [{$value}].");
    }
}
