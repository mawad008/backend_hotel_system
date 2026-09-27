<?php

namespace App\Domain\StayServices\Exceptions;

use RuntimeException;

/**
 * Thrown when a ServiceOrder status change is not in the approved
 * ServiceOrderStateMachine transition table. The message names only the two
 * statuses — no internal detail.
 */
class InvalidServiceOrderStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {
        parent::__construct(
            "Cannot transition a service order from '{$fromStatus}' to '{$toStatus}'."
        );
    }
}
