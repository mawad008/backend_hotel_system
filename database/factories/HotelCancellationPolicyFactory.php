<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\HotelCancellationPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HotelCancellationPolicy>
 */
class HotelCancellationPolicyFactory extends Factory
{
    protected $model = HotelCancellationPolicy::class;

    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'notice_period_hours' => fake()->randomElement([24, 48, 72]),
            'penalty_type' => fake()->randomElement([
                HotelCancellationPolicy::PENALTY_PERCENTAGE,
                HotelCancellationPolicy::PENALTY_FLAT,
                HotelCancellationPolicy::PENALTY_NONE,
            ]),
            'penalty_value' => fake()->randomFloat(2, 0, 100),
        ];
    }
}
