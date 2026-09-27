<?php

namespace Database\Factories;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Checkout>
 */
class CheckoutFactory extends Factory
{
    protected $model = Checkout::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory()->state(['status' => Reservation::STATUS_CHECKOUT_IN_PROGRESS]),
            'hotel_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->hotel_id,
            'status' => Checkout::STATUS_IN_PROGRESS,
            'charges_total' => '0.00',
            'payments_total' => '0.00',
            'outstanding_total' => '0.00',
            'currency' => null,
            'settlement_transaction_id' => null,
            'started_at' => now(),
            'completed_at' => null,
            'created_by_user_id' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => Checkout::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function awaitingSettlement(): static
    {
        return $this->state(fn () => ['status' => Checkout::STATUS_AWAITING_SETTLEMENT]);
    }

    public function settlementFailed(): static
    {
        return $this->state(fn () => ['status' => Checkout::STATUS_SETTLEMENT_FAILED]);
    }
}
