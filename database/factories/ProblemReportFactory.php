<?php

namespace Database\Factories;

use App\Domain\Reservation\Models\Reservation;
use App\Domain\Support\Models\ProblemReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProblemReport>
 */
class ProblemReportFactory extends Factory
{
    protected $model = ProblemReport::class;

    public function definition(): array
    {
        $reservation = Reservation::factory()->create();

        return [
            'reservation_id' => $reservation->id,
            'guest_id' => $reservation->guest_id,
            'hotel_id' => $reservation->hotel_id,
            'category' => $this->faker->randomElement(ProblemReport::CATEGORIES),
            'urgency' => $this->faker->randomElement(ProblemReport::URGENCIES),
            'notes' => $this->faker->optional()->sentence(),
            'status' => ProblemReport::STATUS_OPEN,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => ProblemReport::STATUS_IN_PROGRESS]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => ProblemReport::STATUS_RESOLVED, 'resolved_at' => now()]);
    }
}
