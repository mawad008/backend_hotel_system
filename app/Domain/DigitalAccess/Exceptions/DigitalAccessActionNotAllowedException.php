<?php

namespace App\Domain\DigitalAccess\Exceptions;

use RuntimeException;

/**
 * Thrown when a lifecycle action (revoke, ...) is requested while the grant
 * is in a status that does not accept it. The message names only the
 * attempted action and the current status — no internal detail.
 */
class DigitalAccessActionNotAllowedException extends RuntimeException
{
    public function __construct(
        public readonly string $action,
        public readonly string $currentStatus,
    ) {
        parent::__construct(
            "Cannot '{$action}' digital access while it is '{$currentStatus}'."
        );
    }
}
