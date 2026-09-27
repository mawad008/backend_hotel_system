<?php

namespace Database\Factories;

use App\Domain\Location\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'country_id' => CountryFactory::new(),
            'name_en' => $name,
            'name_ar' => $name,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function forCountry(int $countryId): static
    {
        return $this->state(fn (array $attributes) => ['country_id' => $countryId]);
    }
}
