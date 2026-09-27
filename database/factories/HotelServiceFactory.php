<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\StayServices\Models\HotelService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HotelService>
 */
class HotelServiceFactory extends Factory
{
    protected $model = HotelService::class;

    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'service_category_id' => null,
            'name' => fake()->unique()->words(2, true).' service',
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 5, 500),
            // Arbitrary test currency — the platform never defaults one
            // (mirrors PaymentFactory). Proves the CHAR(3) column round-trips.
            'currency' => 'USD',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function priced(string $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }

    public function withoutCurrency(): static
    {
        return $this->state(fn () => ['currency' => null]);
    }
}
