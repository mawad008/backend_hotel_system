<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Review\Models\ReviewCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test data only — real categories are created from the dashboard.
 *
 * @extends Factory<ReviewCategory>
 */
class ReviewCategoryFactory extends Factory
{
    protected $model = ReviewCategory::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'hotel_id' => Hotel::factory(),
            'name' => $name,
            'name_ar' => null,
            'name_en' => $name,
            'description' => null,
            'icon' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
