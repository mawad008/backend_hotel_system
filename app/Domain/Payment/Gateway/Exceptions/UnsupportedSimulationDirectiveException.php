<?php

namespace App\Domain\Payment\Gateway\Exceptions;

use RuntimeException;

/**
 * Thrown when a simulation directive value (from configuration or a
 * caller) is not one of the approved SimulationDirective cases. Fails
 * clearly rather than guessing an outcome (Phase 5B §16).
 */
class UnsupportedSimulationDirectiveException extends RuntimeException
{
    public function __construct(public readonly string $directive)
    {
        parent::__construct("Unsupported payment simulation directive: '{$directive}'.");
    }
}
