<?php

namespace App\Domain\Checkout\Exceptions;

use RuntimeException;

/**
 * Defensive guard for an unexpected inconsistency while finalizing an
 * invoice (e.g. a totals mismatch that should be impossible). Message is a
 * fixed safe string — no internal detail.
 */
class InvoiceGenerationException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'invoice_generation_failed')
    {
        parent::__construct("The invoice could not be finalized ({$reason}).");
    }
}
