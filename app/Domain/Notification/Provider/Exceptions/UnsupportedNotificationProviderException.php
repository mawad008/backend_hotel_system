<?php

namespace App\Domain\Notification\Provider\Exceptions;

use RuntimeException;

/**
 * Phase 11 — `config('notifications.provider')` names a provider with no
 * registered implementation. A configuration error: it fails loudly rather
 * than silently falling back, mirroring the payment / identity / access
 * provider bindings.
 */
class UnsupportedNotificationProviderException extends RuntimeException
{
    public function __construct(string $provider)
    {
        parent::__construct("Unsupported notification provider [{$provider}].");
    }
}
