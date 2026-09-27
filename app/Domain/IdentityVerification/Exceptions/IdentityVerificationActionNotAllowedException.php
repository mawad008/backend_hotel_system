<?php

namespace App\Domain\IdentityVerification\Exceptions;

use RuntimeException;

/**
 * Thrown when a workflow action (submit selfie, manual review, ...) is
 * requested while the session is in a status that does not accept it. The
 * message names only the current status and the attempted action — no
 * internal implementation detail.
 */
class IdentityVerificationActionNotAllowedException extends RuntimeException
{
    public function __construct(
        public readonly string $action,
        public readonly string $currentStatus,
    ) {
        parent::__construct(
            "Cannot '{$action}' an identity verification while it is '{$currentStatus}'."
        );
    }
}
