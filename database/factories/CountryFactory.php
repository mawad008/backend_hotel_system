<?php

namespace Database\Factories;

use App\Domain\Location\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    public function definition(): array
    {
        $name = fake()->unique()->country();

        return [
            'name_en' => $name,
            'name_ar' => $name,
            'code' => strtoupper(Str::random(2).fake()->unique()->numberBetween(10, 99)),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
