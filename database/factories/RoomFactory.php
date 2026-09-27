<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'room_type_id' => RoomType::factory(),
            // A Room must always belong to the same hotel as its Room Type
            // (approved Hybrid model, enforced in the domain layer from
            // Phase 2B onward) — derived here rather than picked
            // independently, so factory-made Rooms can never violate it.
            'hotel_id' => fn (array $attributes) => RoomType::findOrFail($attributes['room_type_id'])->hotel_id,
            'room_number' => (string) fake()->unique()->numberBetween(100, 99999),
            'status' => 'available',
        ];
    }

    public function underMaintenance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'under_maintenance',
        ]);
    }

    /**
     * `booked` is reserved for the future Reservations domain (Phase 4) and
     * is never reachable through the Phase 2 API. This state exists only so
     * tests can verify the database/model can represent the value the
     * approved Hybrid model reserves for it.
     */
    public function booked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'booked',
        ]);
    }
}
