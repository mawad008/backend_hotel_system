<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when a final settlement is requested for a Payment that is not in a
 * settleable state — there is no active hold or captured amount to settle
 * against. The message names only the current payment status (a safe
 * business enum value). The reservation stays in a safe non-final state.
 */
class PaymentSettlementNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $currentStatus)
    {
        parent::__construct(
            "A final settlement is not allowed while the payment is '{$currentStatus}'."
        );
    }
}
