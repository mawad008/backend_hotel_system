<?php

namespace App\Domain\Payment\Repositories;

use App\Domain\Payment\Models\PaymentWebhookEvent;
use App\Domain\Payment\Repositories\Contracts\PaymentWebhookEventRepositoryInterface;

class EloquentPaymentWebhookEventRepository implements PaymentWebhookEventRepositoryInterface
{
    public function find(int $id): ?PaymentWebhookEvent
    {
        return PaymentWebhookEvent::query()->find($id);
    }

    public function findByProviderEventId(string $provider, string $providerEventId): ?PaymentWebhookEvent
    {
        return PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->first();
    }

    public function findLatestByPayloadHash(string $provider, string $payloadHash): ?PaymentWebhookEvent
    {
        return PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where('payload_hash', $payloadHash)
            ->orderByDesc('id')
            ->first();
    }

    public function create(array $data): PaymentWebhookEvent
    {
        return PaymentWebhookEvent::create($data)->refresh();
    }

    public function update(PaymentWebhookEvent $event, array $data): PaymentWebhookEvent
    {
        $event->update($data);

        return $event->refresh();
    }
}
