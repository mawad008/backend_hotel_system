<?php

namespace App\Domain\Checkout\Exceptions;

use RuntimeException;

/**
 * Thrown when a Checkout status change is not in the approved
 * CheckoutStateMachine transition table. Message names only the two
 * statuses — no internal detail.
 */
class InvalidCheckoutStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {
        parent::__construct(
            "Cannot transition a checkout from '{$fromStatus}' to '{$toStatus}'."
        );
    }
}
