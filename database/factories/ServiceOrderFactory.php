<?php

namespace Database\Factories;

use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\StateMachine\ServiceOrderStateMachine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceOrder>
 */
class ServiceOrderFactory extends Factory
{
    protected $model = ServiceOrder::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory()->state(['status' => Reservation::STATUS_CHECKED_IN]),
            // hotel_id / service always resolve to the reservation's hotel —
            // a factory-made order can never be cross-hotel.
            'hotel_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->hotel_id,
            'service_id' => fn (array $attributes) => HotelService::factory()->create([
                'hotel_id' => Reservation::findOrFail($attributes['reservation_id'])->hotel_id,
            ])->id,
            'quantity' => 2,
            'unit_price_snapshot' => fn (array $attributes) => HotelService::findOrFail($attributes['service_id'])->price,
            'currency_snapshot' => fn (array $attributes) => HotelService::findOrFail($attributes['service_id'])->currency,
            'total_amount' => fn (array $attributes) => bcmul(
                (string) $attributes['unit_price_snapshot'],
                (string) $attributes['quantity'],
                2,
            ),
            'status' => ServiceOrderStateMachine::INITIAL_STATUS,
            'notes' => null,
            'requested_by_user_id' => null,
            'requested_at' => now(),
            'confirmed_at' => null,
            'fulfilled_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => ServiceOrder::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function fulfilled(): static
    {
        return $this->state(fn () => [
            'status' => ServiceOrder::STATUS_FULFILLED,
            'confirmed_at' => now(),
            'fulfilled_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => ServiceOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'test cancellation',
        ]);
    }
}
