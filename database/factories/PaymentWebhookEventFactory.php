<?php

namespace Database\Factories;

use App\Domain\Payment\Models\PaymentWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentWebhookEvent>
 */
class PaymentWebhookEventFactory extends Factory
{
    protected $model = PaymentWebhookEvent::class;

    public function definition(): array
    {
        $providerReference = 'dummy_ref_'.fake()->unique()->numerify('##########');
        $eventType = fake()->randomElement(['hold_succeeded', 'hold_failed']);

        return [
            'provider' => 'dummy',
            'provider_event_id' => (string) fake()->unique()->uuid(),
            'payload_hash' => hash('sha256', (string) fake()->unique()->uuid()),
            // The authoritative normalized vocabulary is defined by the
            // normalizer in a later sub-phase; these are representative
            // test values only.
            'event_type' => $eventType,
            'processing_status' => PaymentWebhookEvent::PROCESSING_RECEIVED,
            // A freshly received event is not matched to a local Payment yet.
            'payment_id' => null,
            'provider_reference' => $providerReference,
            'normalized_payload' => [
                'event_type' => $eventType,
                'provider_reference' => $providerReference,
            ],
            'processed_at' => null,
            'error_message' => null,
        ];
    }

    /**
     * An event with no provider event id — exercises the payload_hash
     * fallback-dedup path.
     */
    public function withoutProviderEventId(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_event_id' => null,
        ]);
    }
}
