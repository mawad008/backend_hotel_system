<?php

namespace Database\Factories;

use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FolioCharge>
 */
class FolioChargeFactory extends Factory
{
    protected $model = FolioCharge::class;

    public function definition(): array
    {
        $quantity = 2;
        $unit = fake()->randomFloat(2, 5, 500);

        return [
            'reservation_id' => Reservation::factory(),
            'hotel_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->hotel_id,
            'source_type' => FolioCharge::SOURCE_SERVICE_ORDER,
            // A distinct soft reference per row so the (source_type,
            // source_id) UNIQUE constraint is not tripped by the factory.
            'source_id' => fn () => fake()->unique()->numberBetween(1, 1_000_000),
            'description' => fake()->words(2, true).' x'.$quantity,
            'quantity' => $quantity,
            'unit_amount' => $unit,
            'total_amount' => bcmul((string) $unit, (string) $quantity, 2),
            'currency' => 'USD',
            'status' => FolioCharge::STATUS_POSTED,
            'charged_at' => now(),
            'cancelled_at' => null,
            'created_by_user_id' => null,
            'metadata' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => FolioCharge::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function amount(string $unit, int $quantity): static
    {
        return $this->state(fn () => [
            'quantity' => $quantity,
            'unit_amount' => $unit,
            'total_amount' => bcmul($unit, (string) $quantity, 2),
        ]);
    }
}
