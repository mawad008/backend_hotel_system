<?php

namespace Database\Factories;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessGrant>
 */
class AccessGrantFactory extends Factory
{
    protected $model = AccessGrant::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            // Hotel and guest are always derived from the Reservation — a
            // factory-made grant can never be cross-hotel or reference a
            // guest that is not on the reservation.
            'hotel_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->hotel_id,
            'guest_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->guest_id,
            'status' => AccessGrant::STATUS_NOT_ISSUED,
            'access_mode' => AccessGrant::MODE_PIN_CODE,
            'provider' => 'dummy',
            'provider_reference' => null,
            'credential' => null,
            'idempotency_key' => (string) Str::uuid(),
            'issued_at' => null,
            'activated_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
            'failure_reason' => null,
            'metadata' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccessGrant::STATUS_ACTIVE,
            'provider_reference' => 'dummy_access_'.substr(md5((string) Str::uuid()), 0, 20),
            'credential' => '482915',
            'issued_at' => now()->subMinutes(2),
            'activated_at' => now()->subMinutes(2),
            'expires_at' => now()->addDays(2)->endOfDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => AccessGrant::STATUS_REVOKED,
            'credential' => null,
            'revoked_at' => now(),
            'revocation_reason' => 'staff revocation',
        ]);
    }
}
