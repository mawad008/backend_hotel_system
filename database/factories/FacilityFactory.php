<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    protected $model = Facility::class;

    public function definition(): array
    {
        $en = fake()->unique()->words(2, true);

        return [
            'key' => Str::slug($en, '_'),
            'name_i18n' => ['en' => ucfirst($en), 'ar' => ucfirst(fake()->words(2, true))],
            'icon' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
