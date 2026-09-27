<?php

namespace Database\Factories;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'type' => PaymentTransaction::TYPE_HOLD,
            'status' => PaymentTransaction::STATUS_PENDING,
            'idempotency_key' => (string) fake()->unique()->uuid(),
            // 'dummy' is the only provider registered this phase
            // (Phase 0 §15: PAYMENT_PROVIDER=dummy).
            'provider' => 'dummy',
            'provider_reference' => null,
            'requested_by_user_id' => null,
            'metadata' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentTransaction::STATUS_SUCCEEDED,
            'provider_reference' => 'dummy_ref_'.fake()->unique()->numerify('##########'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentTransaction::STATUS_FAILED,
        ]);
    }

    public function settlement(): static
    {
        return $this->state(fn () => ['type' => PaymentTransaction::TYPE_SETTLEMENT]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => PaymentTransaction::STATUS_PENDING]);
    }
}
