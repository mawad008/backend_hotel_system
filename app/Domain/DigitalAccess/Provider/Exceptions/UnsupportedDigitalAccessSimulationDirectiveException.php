<?php

namespace App\Domain\DigitalAccess\Provider\Exceptions;

use RuntimeException;

/**
 * Phase 7 — thrown when a digital access simulation directive (config
 * default, or a controlled local/testing header) is not one of the approved
 * values. Fails loudly rather than silently defaulting.
 */
class UnsupportedDigitalAccessSimulationDirectiveException extends RuntimeException
{
    public function __construct(public readonly string $value)
    {
        parent::__construct("Unsupported digital access simulation directive: '{$value}'.");
    }
}
