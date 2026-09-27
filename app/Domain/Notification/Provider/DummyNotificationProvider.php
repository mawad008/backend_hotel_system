<?php

namespace App\Domain\Notification\Provider;

use App\Domain\Notification\Provider\Contracts\NotificationProviderInterface;
use App\Domain\Notification\Provider\Data\NotificationDeliveryResult;
use App\Domain\Notification\Provider\Data\NotificationDispatchRequest;

/**
 * Phase 11 — the deterministic dummy notification provider (Phase 0 §15,
 * R50: "all third-party integrations dummy/mocked this phase").
 *
 * It is a pure provider-boundary simulation:
 *  - no randomness, no sleep/delay, no time-based behaviour
 *  - no external HTTP, no email/SMS/push SDK, no real credentials
 *  - no Eloquent model, no repository, no DB access, no audit write
 *  - no authorization / recipient-resolution / business logic
 *
 * Every outcome is a pure function of (channel, simulation directive,
 * caller-supplied destination reference). The directive is either given
 * explicitly on the request or falls back to the configured default
 * (deterministic success). The same inputs always produce an equivalent
 * result — so CI can assert every branch (§18).
 *
 * A single class handles every channel: §15's "one adapter per channel"
 * sketch is realized here as one deterministic simulator, and the
 * per-channel real adapters are the documented future-provider path.
 */
final class DummyNotificationProvider implements NotificationProviderInterface
{
    /**
     * Hex chars of SHA-256 kept for a provider reference. 24 hex = 96 bits.
     */
    private const REFERENCE_HEX_LENGTH = 24;

    public function __construct(
        private readonly SimulationDirective $defaultDirective = SimulationDirective::DEFAULT,
    ) {}

    public function send(NotificationDispatchRequest $request): NotificationDeliveryResult
    {
        $directive = $request->directive ?? $this->defaultDirective;
        $outcome = NotificationDeliveryOutcome::forDirective($directive);
        $channel = $request->channel->value;

        return new NotificationDeliveryResult(
            outcome: $outcome,
            providerReference: $this->reference($channel, $request->destinationReference),
            providerCode: sprintf('dummy_notification_%s_%s', $channel, $outcome->value),
            message: $outcome === NotificationDeliveryOutcome::Sent
                ? sprintf('Dummy provider recorded a %s notification. No external message was sent.', $channel)
                : sprintf('Dummy provider could not deliver the %s notification.', $channel),
            context: $request->context,
        );
    }

    private function reference(string $channel, string $seed): string
    {
        $hex = substr(hash('sha256', 'notification|'.$channel.'|'.$seed), 0, self::REFERENCE_HEX_LENGTH);

        return sprintf('dummy_notification_%s_%s', $channel, $hex);
    }
}
