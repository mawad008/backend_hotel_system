<?php

namespace App\Domain\Notification\Provider\Data;

use App\Domain\Notification\Provider\NotificationDeliveryOutcome;

/**
 * Phase 11 — the normalized outcome of a single provider send.
 *
 * This is all the provider returns. It creates and updates nothing: the
 * workflow layer decides what NotificationStatus transition follows from it.
 *
 * `providerReference` is deterministic. Neither `message` nor `context` ever
 * contains a secret, a recipient address, or a raw provider payload.
 */
final class NotificationDeliveryResult
{
    /**
     * @param  array<string, scalar|null>  $context  echoed, sanitized request context
     */
    public function __construct(
        public readonly NotificationDeliveryOutcome $outcome,
        public readonly string $providerReference,
        public readonly string $providerCode,
        public readonly string $message,
        public readonly array $context = [],
    ) {}

    public function isFailure(): bool
    {
        return $this->outcome === NotificationDeliveryOutcome::Failed;
    }

    /**
     * A plain, safe array representation for a later step to fold into a
     * Notification's context.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'provider_reference' => $this->providerReference,
            'provider_code' => $this->providerCode,
            'message' => $this->message,
        ];
    }
}
