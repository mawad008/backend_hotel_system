<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested payment amount is not a positive value that fits
 * the approved DECIMAL(12,2) column without unsafe rounding (Phase 5C §29).
 *
 * Phase 5C does not invent maximum business limits — only the schema
 * precision and the positive-value rule are enforced here.
 */
class InvalidPaymentAmountException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The payment amount is invalid ({$reason}).");
    }
}
