<?php

namespace App\Domain\Checkout\Exceptions;

use RuntimeException;

/**
 * Thrown when a final settlement is required (outstanding > 0) but no
 * currency can be resolved from the payment or the folio. Currency is a
 * genuinely unresolved business decision (Phase 0 §20 item 7) — the
 * workflow fails safely here rather than silently choosing one. It is thrown
 * inside checkout STEP A, so the whole transaction rolls back: the
 * reservation stays exactly as it was (IN_STAY) and nothing is persisted. A
 * clean retry is possible once a currency is configured.
 */
class CheckoutCurrencyMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'A final settlement is required but no currency is set on the payment or the folio.'
        );
    }
}
