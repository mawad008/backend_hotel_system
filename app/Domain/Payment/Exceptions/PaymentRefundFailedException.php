<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * The provider did not release the deposit hold (the refund of a free
 * cancellation). The reservation is left unchanged — a cancellation is
 * never reported as refunded when the money was not released.
 */
class PaymentRefundFailedException extends RuntimeException
{
    public function __construct(public readonly string $providerCode)
    {
        parent::__construct("The deposit could not be released ({$providerCode}).");
    }
}
