<?php

namespace App\Domain\Payment\Repositories\Contracts;

use App\Domain\Payment\Models\PaymentWebhookEvent;

interface PaymentWebhookEventRepositoryInterface
{
    public function find(int $id): ?PaymentWebhookEvent;

    /**
     * Lookup by the primary deduplication key (Phase 5 plan v2 §7.3).
     * Only meaningful when the provider supplied an event id.
     */
    public function findByProviderEventId(string $provider, string $providerEventId): ?PaymentWebhookEvent;

    /**
     * The most recent event for `(provider, payload_hash)`. The
     * `payload_hash` index is deliberately NON-unique (Phase 5A) — this is
     * the fallback deduplication lookup used only when a provider event id
     * is absent (Phase 5E §8/§15).
     */
    public function findLatestByPayloadHash(string $provider, string $payloadHash): ?PaymentWebhookEvent;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PaymentWebhookEvent;

    /**
     * Persist $data onto $event and return the refreshed model. Pure
     * persistence — PaymentWebhookService owns the processing-status
     * decisions.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PaymentWebhookEvent $event, array $data): PaymentWebhookEvent;
}
