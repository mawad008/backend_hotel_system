<?php

namespace Tests\Unit\Models;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentWebhookEventTest extends TestCase
{
    public function test_factory_creates_a_valid_received_event(): void
    {
        $event = PaymentWebhookEvent::factory()->create();

        $this->assertDatabaseHas('payment_webhook_events', ['id' => $event->id]);
        $this->assertSame(PaymentWebhookEvent::PROCESSING_RECEIVED, $event->processing_status);
    }

    public function test_provider_event_id_is_nullable(): void
    {
        $event = PaymentWebhookEvent::factory()->withoutProviderEventId()->create();

        $this->assertNull($event->fresh()->provider_event_id);
    }

    public function test_provider_plus_provider_event_id_is_unique_when_present(): void
    {
        PaymentWebhookEvent::factory()->create(['provider' => 'dummy', 'provider_event_id' => 'evt-1']);

        $this->expectException(QueryException::class);

        PaymentWebhookEvent::factory()->create(['provider' => 'dummy', 'provider_event_id' => 'evt-1']);
    }

    public function test_multiple_events_with_a_null_provider_event_id_are_allowed(): void
    {
        PaymentWebhookEvent::factory()->withoutProviderEventId()->create(['provider' => 'dummy']);
        PaymentWebhookEvent::factory()->withoutProviderEventId()->create(['provider' => 'dummy']);

        $this->assertSame(2, PaymentWebhookEvent::whereNull('provider_event_id')->count());
    }

    public function test_payload_hash_is_indexed_but_not_universally_unique(): void
    {
        // Behavioural proof: the same payload_hash on two events (no
        // provider_event_id) must not be rejected.
        $sharedHash = hash('sha256', 'identical-body');
        PaymentWebhookEvent::factory()->withoutProviderEventId()->create(['payload_hash' => $sharedHash]);
        PaymentWebhookEvent::factory()->withoutProviderEventId()->create(['payload_hash' => $sharedHash]);

        $this->assertSame(2, PaymentWebhookEvent::where('payload_hash', $sharedHash)->count());

        // Schema proof: an index exists on (provider, payload_hash) and it
        // is NOT unique, while (provider, provider_event_id) IS unique.
        $indexes = collect(Schema::getIndexes('payment_webhook_events'));

        $payloadHashIndex = $indexes->first(fn (array $i) => $i['columns'] === ['provider', 'payload_hash']);
        $this->assertNotNull($payloadHashIndex, 'expected an index on (provider, payload_hash)');
        $this->assertFalse($payloadHashIndex['unique'], 'payload_hash index must not be unique');

        $eventIdIndex = $indexes->first(fn (array $i) => $i['columns'] === ['provider', 'provider_event_id']);
        $this->assertNotNull($eventIdIndex, 'expected an index on (provider, provider_event_id)');
        $this->assertTrue($eventIdIndex['unique'], '(provider, provider_event_id) must be unique');
    }

    public function test_all_five_processing_statuses_are_accepted(): void
    {
        $this->assertCount(5, PaymentWebhookEvent::PROCESSING_STATUSES);

        foreach (PaymentWebhookEvent::PROCESSING_STATUSES as $status) {
            $event = PaymentWebhookEvent::factory()->create(['processing_status' => $status]);
            $this->assertSame($status, $event->fresh()->processing_status);
        }
    }

    public function test_column_rejects_an_unapproved_processing_status(): void
    {
        $event = PaymentWebhookEvent::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('payment_webhook_events')->where('id', $event->id)->update(['processing_status' => 'queued']);
    }

    public function test_payment_id_is_nullable_for_an_unmatched_event(): void
    {
        $event = PaymentWebhookEvent::factory()->create();

        $this->assertNull($event->fresh()->payment_id);
        $this->assertNull($event->payment);
    }

    public function test_can_be_matched_to_a_payment_and_nulls_on_payment_delete(): void
    {
        $payment = Payment::factory()->create();
        $event = PaymentWebhookEvent::factory()->create(['payment_id' => $payment->id]);

        $this->assertTrue($event->payment->is($payment));

        // Deleting the payment itself is blocked elsewhere; force-remove the
        // row to prove the FK is nullOnDelete, not restrictive, for events.
        DB::table('payments')->where('id', $payment->id)->delete();

        $this->assertNull($event->fresh()->payment_id);
    }

    public function test_normalized_payload_casts_to_and_from_an_array(): void
    {
        $event = PaymentWebhookEvent::factory()->create([
            'normalized_payload' => ['event_type' => 'hold_succeeded', 'provider_reference' => 'r9'],
        ]);

        $this->assertSame(
            ['event_type' => 'hold_succeeded', 'provider_reference' => 'r9'],
            $event->fresh()->normalized_payload,
        );
    }

    public function test_event_type_error_message_and_processed_at_are_nullable(): void
    {
        $event = PaymentWebhookEvent::factory()->create([
            'event_type' => null,
            'error_message' => null,
            'processed_at' => null,
        ]);

        $fresh = $event->fresh();
        $this->assertNull($fresh->event_type);
        $this->assertNull($fresh->error_message);
        $this->assertNull($fresh->processed_at);
    }
}
