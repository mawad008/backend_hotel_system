<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        $checkIn = fake()->dateTimeBetween('+1 day', '+30 days');
        $checkOut = (clone $checkIn)->modify('+'.fake()->numberBetween(1, 14).' days');

        return [
            'room_type_id' => RoomType::factory(),
            // A Reservation must always belong to the same hotel as its
            // Room Type (approved Hybrid model, §6.2) — derived here rather
            // than picked independently, mirroring RoomFactory's pattern.
            'hotel_id' => fn (array $attributes) => RoomType::findOrFail($attributes['room_type_id'])->hotel_id,
            'room_id' => null,
            'guest_id' => Guest::factory(),
            'check_in' => $checkIn->format('Y-m-d'),
            'check_out' => $checkOut->format('Y-m-d'),
            'adults' => fake()->numberBetween(1, 2),
            'children' => 0,
            'status' => Reservation::STATUS_PENDING,
            'price_snapshot' => fake()->randomFloat(2, 50, 5000),
            // Approved policy snapshot: refundable, free window still open.
            'is_refundable' => true,
            'free_cancellation_until' => now()->addDay(),
            'created_by_staff_id' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    /**
     * Assigns a Room belonging to the same hotel and Room Type as the
     * reservation (approved Hybrid model rule §6.2.7). Uses afterCreating
     * rather than a state closure because the Reservation's own hotel_id/
     * room_type_id are only resolved to concrete IDs once it is actually
     * persisted — a state closure would still see the raw, unresolved
     * factory definitions and could create a mismatched Room.
     */
    public function withRoom(): static
    {
        return $this->afterCreating(function (Reservation $reservation) {
            $reservation->forceFill([
                'room_id' => Room::factory()->create([
                    'hotel_id' => $reservation->hotel_id,
                    'room_type_id' => $reservation->room_type_id,
                ])->id,
            ])->save();
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Reservation::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }
}
