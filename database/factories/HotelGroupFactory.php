<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\HotelGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HotelGroup>
 */
class HotelGroupFactory extends Factory
{
    protected $model = HotelGroup::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'is_active' => true,
        ];
    }
}
