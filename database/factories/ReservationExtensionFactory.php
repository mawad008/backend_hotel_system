<?php

namespace Database\Factories;

use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Models\ReservationExtension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationExtension>
 */
class ReservationExtensionFactory extends Factory
{
    protected $model = ReservationExtension::class;

    public function definition(): array
    {
        $nightsAdded = 2;
        $unitPrice = fake()->randomFloat(2, 100, 900);

        return [
            'reservation_id' => Reservation::factory(),
            'hotel_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->hotel_id,
            'previous_check_out' => now()->toDateString(),
            'new_check_out' => now()->addDays($nightsAdded)->toDateString(),
            'nights_added' => $nightsAdded,
            'unit_price' => $unitPrice,
            'amount' => bcmul((string) $unitPrice, (string) $nightsAdded, 2),
            'currency' => 'SAR',
            'folio_charge_id' => null,
            'idempotency_key' => null,
            'created_by_staff_id' => null,
        ];
    }
}
