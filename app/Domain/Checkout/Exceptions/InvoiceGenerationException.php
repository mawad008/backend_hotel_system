<?php

namespace App\Domain\Checkout\Exceptions;

use App\Support\ErrorText;
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
        parent::__construct(ErrorText::message('invoice_generation_failed', ['reason' => $reason]));
    }
}
