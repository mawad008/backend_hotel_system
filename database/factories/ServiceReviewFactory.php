<?php

namespace Database\Factories;

use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Models\ServiceReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceReview>
 */
class ServiceReviewFactory extends Factory
{
    protected $model = ServiceReview::class;

    public function definition(): array
    {
        $order = ServiceOrder::factory()->fulfilled()->create();

        return [
            'service_order_id' => $order->id,
            'guest_id' => $order->reservation->guest_id,
            'hotel_id' => $order->hotel_id,
            'service_id' => $order->service_id,
            'rating' => $this->faker->numberBetween(1, 5),
            'text' => $this->faker->optional()->sentence(),
            'status' => ServiceReview::STATUS_PENDING,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => ServiceReview::STATUS_PUBLISHED, 'moderated_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => ServiceReview::STATUS_REJECTED, 'moderated_at' => now()]);
    }
}
