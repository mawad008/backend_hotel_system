<?php

namespace App\Domain\Lifecycle\Exceptions;

use RuntimeException;

/**
 * The real flow behind a dashboard "move to …" ran but did not reach the
 * requested status — e.g. the deposit hold was declined, the provider did
 * not issue the key, or the final settlement is still pending. Nothing is
 * forced past it: the reservation stays where the flow left it, exactly as
 * it would in the app.
 */
class LifecycleStepIncompleteException extends RuntimeException
{
    public function __construct(
        public readonly string $targetStatus,
        public readonly string $currentStatus,
        public readonly string $reason,
    ) {
        parent::__construct("The move to {$targetStatus} did not complete ({$reason}); the reservation is {$currentStatus}.");
    }
}
