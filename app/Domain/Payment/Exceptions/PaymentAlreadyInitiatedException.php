<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when a payment hold is requested for a Reservation that already
 * has a Payment whose hold is in progress or resolved, and the request is
 * not an idempotent retry of that same operation (Phase 5C §7, §10, §26).
 *
 * A second, differently-keyed initiation must never create a second
 * Payment row or a second provider call.
 */
class PaymentAlreadyInitiatedException extends RuntimeException
{
    public function __construct(public readonly string $paymentStatus)
    {
        parent::__construct(
            "A payment hold has already been initiated for this reservation (payment is '{$paymentStatus}')."
        );
    }
}
