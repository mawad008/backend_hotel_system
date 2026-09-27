<?php

namespace App\Domain\Checkout\Exceptions;

use RuntimeException;

/**
 * Thrown when checkout cannot start because the reservation is not in a
 * state that permits it (Phase 0 §12: checkout begins from IN_STAY). The
 * message names only the current reservation status — a safe business enum
 * value, no internal detail.
 */
class CheckoutNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $currentStatus)
    {
        parent::__construct(
            "Checkout is not allowed while the reservation is '{$currentStatus}'."
        );
    }
}
