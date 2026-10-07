<?php

namespace App\Domain\DigitalAccess\Exceptions;

use App\Support\ErrorText;
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
            ErrorText::message('digital_access_action_not_allowed', ['action' => ErrorText::action($action), 'status' => ErrorText::status($currentStatus)])
        );
    }
}
