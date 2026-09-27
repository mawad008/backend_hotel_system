<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\StayServices\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            // UNIQUE(hotel_id, name) — a Faker-unique suffix keeps generated
            // names distinct for the life of the test process.
            'name' => fake()->randomElement(['Dining', 'Transport', 'Housekeeping', 'Spa', 'Room Service']).' '.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
