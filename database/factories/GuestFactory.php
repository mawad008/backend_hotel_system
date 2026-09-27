<?php

namespace Database\Factories;

use App\Domain\Reservation\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+'.fake()->unique()->numerify('9665########'),
            'phone_verified_at' => now(),
            'profile_completed_at' => now(),
        ];
    }

    /**
     * A first-time guest: phone proven, but the name/email step not done.
     */
    public function unregistered(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => null,
            'email' => null,
            'profile_completed_at' => null,
        ]);
    }

    public function unverifiedPhone(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_verified_at' => null,
        ]);
    }
}
