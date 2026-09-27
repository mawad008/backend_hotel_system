<?php

namespace App\Domain\Notification\Provider\Data;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Provider\SimulationDirective;
use App\Domain\Notification\Provider\Support\NotificationSensitiveDataGuard;

/**
 * Phase 11 — the input to NotificationProviderInterface::send.
 *
 * A pure application-layer value object: no Eloquent model, no repository,
 * no persistence concern. The workflow layer builds one of these from a
 * persisted Notification row and is responsible for applying whatever the
 * provider returns.
 *
 * `destinationReference` is an OPAQUE routing token — never the recipient's
 * real email/phone. The dummy provider uses it purely as a deterministic
 * seed. A real adapter would map it to a real address from its own secure
 * lookup; the address never travels through this layer or gets persisted.
 *
 * Carries NO secret — `context` is denylist-checked on construction.
 */
final class NotificationDispatchRequest
{
    /**
     * @param  array<string, scalar|null>  $context  safe, non-secret context only
     */
    public function __construct(
        public readonly NotificationChannel $channel,
        public readonly string $destinationReference,
        public readonly string $type,
        public readonly string $locale,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?SimulationDirective $directive = null,
        public readonly array $context = [],
    ) {
        NotificationSensitiveDataGuard::assertClean($this->context, 'dispatch request');
    }
}
