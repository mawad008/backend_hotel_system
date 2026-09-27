<?php

namespace App\Domain\Payment\Gateway\Data;

use App\Domain\Payment\Gateway\GatewayResultStatus;

/**
 * Phase 5B — a provider-agnostic representation of one inbound webhook /
 * async callback, produced by PaymentGatewayInterface::parseWebhook.
 *
 * parseWebhook ONLY normalizes. It writes nothing: no PaymentWebhookEvent
 * row, no Payment update, no transaction status change. Persistence and
 * idempotency handling are Phase 5E.
 *
 * `payloadHash` is the sha256 of the exact raw body, provided so a later
 * phase can dedupe / audit without the gateway (or that phase) ever
 * retaining the raw body itself. `normalizedPayload` is an explicit
 * allow-list of safe fields — sensitive keys are rejected before this
 * object is built, never merely stripped afterwards.
 */
final class NormalizedWebhook
{
    /**
     * @param  array<string, scalar|null>  $normalizedPayload
     */
    public function __construct(
        public readonly ?string $providerEventId,
        public readonly string $eventType,
        public readonly ?string $providerReference,
        public readonly ?GatewayResultStatus $status,
        public readonly string $payloadHash,
        public readonly array $normalizedPayload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_event_id' => $this->providerEventId,
            'event_type' => $this->eventType,
            'provider_reference' => $this->providerReference,
            'status' => $this->status?->value,
            'payload_hash' => $this->payloadHash,
            'normalized_payload' => $this->normalizedPayload,
        ];
    }
}
