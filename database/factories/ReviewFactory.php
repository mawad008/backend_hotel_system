<?php

namespace Database\Factories;

use App\Domain\Reservation\Models\Reservation;
use App\Domain\Review\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_INVOICED]);

        return [
            'reservation_id' => $reservation->id,
            'guest_id' => $reservation->guest_id,
            'hotel_id' => $reservation->hotel_id,
            'rating' => $this->faker->numberBetween(1, 5),
            'text' => $this->faker->optional()->sentence(),
            'status' => Review::STATUS_PENDING,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => Review::STATUS_PUBLISHED, 'moderated_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => Review::STATUS_REJECTED, 'moderated_at' => now()]);
    }
}
