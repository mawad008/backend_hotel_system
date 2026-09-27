<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationRecipientType;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\Notification;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'hotel_id' => Hotel::factory(),
            'recipient_type' => NotificationRecipientType::Guest->value,
            'recipient_id' => fn () => fake()->unique()->numberBetween(1, 1_000_000),
            'type' => NotificationType::ReservationDepositHeld->value,
            'channel' => NotificationChannel::InApp->value,
            'status' => NotificationStatus::Sent->value,
            'locale' => 'en',
            'subject' => 'Deposit confirmed',
            'body' => 'The deposit hold for reservation #1 has been confirmed.',
            'provider' => 'dummy',
            'provider_reference' => fn () => 'dummy_notification_in_app_'.fake()->unique()->sha1(),
            'provider_code' => 'dummy_notification_in_app_sent',
            'failure_reason' => null,
            'idempotency_key' => fn () => 'reservation:'.fake()->unique()->numberBetween(1, 1_000_000).':deposit_held:in_app',
            'context' => ['from_status' => 'pending', 'to_status' => 'deposit_held'],
            'sent_at' => now(),
            'failed_at' => null,
            'read_at' => null,
        ];
    }

    public function channel(NotificationChannel $channel): static
    {
        return $this->state(fn () => ['channel' => $channel->value]);
    }

    public function type(NotificationType $type): static
    {
        return $this->state(fn () => ['type' => $type->value]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => NotificationStatus::Pending->value,
            'provider_reference' => null,
            'provider_code' => null,
            'sent_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => NotificationStatus::Failed->value,
            'failure_reason' => 'provider_declined',
            'provider_code' => 'dummy_notification_in_app_failed',
            'sent_at' => null,
            'failed_at' => now(),
        ]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }

    public function forReservation(Reservation $reservation): static
    {
        return $this->state(fn () => [
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'recipient_id' => $reservation->guest_id,
        ]);
    }
}
