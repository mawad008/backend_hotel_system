<?php

namespace App\Domain\Payment\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when a supplied currency is not a well-formed ISO-4217 alpha
 * code (Phase 5C §30).
 *
 * Phase 5C never invents a business default currency — a null currency is
 * valid and preserved; only a malformed non-null value is rejected here.
 */
class InvalidPaymentCurrencyException extends RuntimeException
{
    public function __construct(public readonly string $currency)
    {
        parent::__construct(ErrorText::message('payment_currency_invalid', ['currency' => $currency]));
    }
}
