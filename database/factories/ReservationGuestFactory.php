<?php

namespace Database\Factories;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Models\ReservationGuest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationGuest>
 */
class ReservationGuestFactory extends Factory
{
    protected $model = ReservationGuest::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'guest_id' => Guest::factory(),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }
}
