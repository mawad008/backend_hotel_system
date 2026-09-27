<?php

namespace Database\Factories;

use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdentityVerificationSession>
 */
class IdentityVerificationSessionFactory extends Factory
{
    protected $model = IdentityVerificationSession::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            // Guest and hotel are always derived from the Reservation — a
            // factory-made session can never be cross-hotel or reference a
            // guest that is not on the reservation.
            'guest_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->guest_id,
            'hotel_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->hotel_id,
            'status' => IdentityVerificationSession::STATUS_NOT_STARTED,
            'provider' => 'dummy',
            'attempts' => 0,
            'latest_outcome' => null,
            'latest_score' => null,
            'decided_at' => null,
        ];
    }

    public function documentUploaded(): static
    {
        return $this->state(fn () => ['status' => IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED]);
    }

    public function pendingManualReview(): static
    {
        return $this->state(fn () => [
            'status' => IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
            'attempts' => 1,
            'latest_outcome' => 'low_match',
            'latest_score' => 20,
        ]);
    }

    public function retryAllowed(): static
    {
        return $this->state(fn () => [
            'status' => IdentityVerificationSession::STATUS_RETRY_ALLOWED,
            'attempts' => 1,
            'latest_outcome' => 'error',
        ]);
    }

    public function autoApproved(): static
    {
        return $this->state(fn () => [
            'status' => IdentityVerificationSession::STATUS_AUTO_APPROVED,
            'attempts' => 1,
            'latest_outcome' => 'high_match',
            'latest_score' => 90,
            'decided_at' => now(),
        ]);
    }
}
