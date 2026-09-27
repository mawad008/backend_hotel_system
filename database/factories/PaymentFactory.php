<?php

namespace Database\Factories;

use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            // A Payment always belongs to the same hotel as its Reservation
            // (Phase 5 plan v2 §14.3) — derived here rather than picked
            // independently, mirroring RoomFactory/ReservationFactory, so a
            // factory-made Payment can never be cross-hotel.
            'hotel_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->hotel_id,
            'status' => Payment::STATUS_NOT_STARTED,
            // Arbitrary test currency — the application never hardcodes or
            // defaults a currency (Phase 5 planning C1 is unresolved); this
            // value only proves the CHAR(3) column round-trips an ISO code.
            'currency' => fake()->currencyCode(),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'hold_expires_at' => null,
            'provider_customer_ref' => null,
        ];
    }

    /**
     * A Payment whose deposit hold has been confirmed by the provider —
     * the state later phases build their capture/settlement flow on.
     */
    public function holdActive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_HOLD_ACTIVE,
            'hold_expires_at' => now()->addDay(),
        ]);
    }

    /**
     * A Payment whose deposit hold has been captured — the state a final
     * settlement (Phase 9) transitions from.
     */
    public function captured(): static
    {
        return $this->state(fn () => ['status' => Payment::STATUS_CAPTURED]);
    }

    /**
     * A fully settled Payment (Phase 9 terminal). `amount` then represents
     * the total collected folio.
     */
    public function settled(): static
    {
        return $this->state(fn () => ['status' => Payment::STATUS_SETTLED]);
    }

    public function settlementFailed(): static
    {
        return $this->state(fn () => ['status' => Payment::STATUS_SETTLEMENT_FAILED]);
    }
}
