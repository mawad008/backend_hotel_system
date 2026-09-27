<?php

namespace Tests\Unit\Repositories;

use App\Domain\Payment\Models\PaymentWebhookEvent;
use App\Domain\Payment\Repositories\Contracts\PaymentWebhookEventRepositoryInterface;
use App\Domain\Payment\Repositories\EloquentPaymentWebhookEventRepository;
use Tests\TestCase;

class PaymentWebhookEventRepositoryTest extends TestCase
{
    private EloquentPaymentWebhookEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentPaymentWebhookEventRepository;
    }

    public function test_it_is_bound_to_the_contract(): void
    {
        $this->assertInstanceOf(
            EloquentPaymentWebhookEventRepository::class,
            app(PaymentWebhookEventRepositoryInterface::class),
        );
    }

    public function test_find_returns_an_event_by_id(): void
    {
        $event = PaymentWebhookEvent::factory()->create();

        $this->assertTrue($this->repository->find($event->id)->is($event));
        $this->assertNull($this->repository->find(999999));
    }

    public function test_find_by_provider_event_id(): void
    {
        $event = PaymentWebhookEvent::factory()->create(['provider' => 'dummy', 'provider_event_id' => 'evt-77']);

        $this->assertTrue($this->repository->findByProviderEventId('dummy', 'evt-77')->is($event));
        $this->assertNull($this->repository->findByProviderEventId('dummy', 'evt-missing'));
    }

    public function test_create_persists_an_event(): void
    {
        $event = $this->repository->create([
            'provider' => 'dummy',
            'provider_event_id' => 'evt-99',
            'payload_hash' => hash('sha256', 'body'),
            'processing_status' => PaymentWebhookEvent::PROCESSING_RECEIVED,
            'normalized_payload' => ['k' => 'v'],
        ]);

        $this->assertDatabaseHas('payment_webhook_events', ['id' => $event->id, 'provider_event_id' => 'evt-99']);
    }

    public function test_find_latest_by_payload_hash_returns_the_most_recent_match(): void
    {
        $hash = hash('sha256', 'same-body');
        PaymentWebhookEvent::factory()->create(['provider' => 'dummy', 'provider_event_id' => null, 'payload_hash' => $hash]);
        $latest = PaymentWebhookEvent::factory()->create(['provider' => 'dummy', 'provider_event_id' => null, 'payload_hash' => $hash]);

        $this->assertTrue($this->repository->findLatestByPayloadHash('dummy', $hash)->is($latest));
        $this->assertNull($this->repository->findLatestByPayloadHash('dummy', hash('sha256', 'other')));
    }

    public function test_update_persists_and_returns_the_refreshed_model(): void
    {
        $event = PaymentWebhookEvent::factory()->create(['processing_status' => PaymentWebhookEvent::PROCESSING_RECEIVED]);

        $updated = $this->repository->update($event, [
            'processing_status' => PaymentWebhookEvent::PROCESSING_PROCESSED,
            'error_message' => null,
        ]);

        $this->assertSame(PaymentWebhookEvent::PROCESSING_PROCESSED, $updated->processing_status);
        $this->assertDatabaseHas('payment_webhook_events', [
            'id' => $event->id,
            'processing_status' => PaymentWebhookEvent::PROCESSING_PROCESSED,
        ]);
    }
}
