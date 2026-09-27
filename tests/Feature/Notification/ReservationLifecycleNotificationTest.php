<?php

namespace Tests\Feature\Notification;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Provider\Contracts\NotificationProviderInterface;
use App\Domain\Reservation\Events\ReservationStatusChanged;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationService;
use Tests\TestCase;

class ReservationLifecycleNotificationTest extends TestCase
{
    private function service(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function reservation(string $status, ?Guest $guest = null): Reservation
    {
        $hotel = Hotel::factory()->create();

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'status' => $status,
            'guest_id' => ($guest ?? Guest::factory()->create())->id,
        ]);
    }

    public function test_deposit_held_transition_notifies_the_guest_on_the_routed_channels(): void
    {
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->service()->transitionTo($reservation, Reservation::STATUS_DEPOSIT_HELD);

        $rows = Notification::query()->where('reservation_id', $reservation->id)->get();
        $this->assertEqualsCanonicalizing(['in_app', 'email'], $rows->pluck('channel')->map->value->all());

        foreach ($rows as $row) {
            $this->assertSame('reservation_deposit_held', $row->type->value);
            $this->assertSame(NotificationStatus::Sent, $row->status);
            $this->assertSame($reservation->guest_id, $row->recipient_id);
            $this->assertSame('guest', $row->recipient_type->value);
            $this->assertSame($reservation->hotel_id, $row->hotel_id);
            $this->assertSame('Deposit confirmed', $row->subject);
            $this->assertStringContainsString('#'.$reservation->id, $row->body);
        }
    }

    public function test_each_approved_milestone_produces_its_own_type(): void
    {
        $cases = [
            [Reservation::STATUS_DEPOSIT_HELD, Reservation::STATUS_VERIFIED, 'identity_verified'],
            [Reservation::STATUS_VERIFIED, Reservation::STATUS_CHECKED_IN, 'reservation_checked_in'],
            [Reservation::STATUS_CHECKED_OUT, Reservation::STATUS_INVOICED, 'reservation_invoiced'],
            [Reservation::STATUS_PENDING, Reservation::STATUS_CANCELLED, 'reservation_cancelled'],
        ];

        foreach ($cases as [$from, $to, $type]) {
            $reservation = $this->reservation($from);
            $this->service()->transitionTo($reservation, $to);

            $this->assertTrue(
                Notification::query()->where('reservation_id', $reservation->id)->where('type', $type)->exists(),
                "expected a {$type} notification for {$from} -> {$to}",
            );
        }
    }

    public function test_checked_in_also_routes_to_sms_when_the_guest_has_a_phone(): void
    {
        $reservation = $this->reservation(Reservation::STATUS_VERIFIED);

        $this->service()->transitionTo($reservation, Reservation::STATUS_CHECKED_IN);

        $this->assertEqualsCanonicalizing(
            ['in_app', 'email', 'sms'],
            Notification::query()->where('reservation_id', $reservation->id)->pluck('channel')->map->value->all(),
        );
    }

    public function test_email_is_skipped_when_the_guest_has_not_completed_their_profile(): void
    {
        // Slice 0: a guest always has a verified phone, but may not yet have
        // supplied a name/email — so the email channel is the one that can
        // legitimately be unreachable.
        $guest = Guest::factory()->unregistered()->create();
        $reservation = $this->reservation(Reservation::STATUS_VERIFIED, $guest);

        $this->service()->transitionTo($reservation, Reservation::STATUS_CHECKED_IN);

        $this->assertEqualsCanonicalizing(
            ['in_app', 'sms'],
            Notification::query()->where('reservation_id', $reservation->id)->pluck('channel')->map->value->all(),
        );
    }

    public function test_a_non_milestone_transition_produces_no_notification(): void
    {
        $reservation = $this->reservation(Reservation::STATUS_CHECKED_IN);

        $this->service()->transitionTo($reservation, Reservation::STATUS_IN_STAY);

        $this->assertSame(0, Notification::query()->where('reservation_id', $reservation->id)->count());
    }

    public function test_repeating_the_same_transition_never_duplicates_a_notification(): void
    {
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->service()->transitionTo($reservation, Reservation::STATUS_DEPOSIT_HELD);
        // Re-fire the exact same event (a listener re-run / retry).
        event(new ReservationStatusChanged(
            $reservation->fresh(),
            Reservation::STATUS_PENDING,
        ));

        $this->assertSame(2, Notification::query()->where('reservation_id', $reservation->id)->count());
    }

    public function test_a_notification_delivery_failure_never_blocks_the_transition(): void
    {
        config()->set('notifications.providers.dummy.default_directive', 'fail');
        $this->app->forgetInstance(NotificationProviderInterface::class);

        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $result = $this->service()->transitionTo($reservation, Reservation::STATUS_DEPOSIT_HELD);

        // The business transition still committed.
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $result->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
        // The notification rows exist and are marked FAILED (retryable).
        $this->assertSame(2, Notification::query()->where('reservation_id', $reservation->id)->where('status', 'failed')->count());
    }

    public function test_the_locale_of_the_triggering_request_is_used(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Locale', 'ar')
            ->postJson("/api/v1/reservations/{$reservation->id}/transition", ['target_status' => Reservation::STATUS_DEPOSIT_HELD])
            ->assertOk();

        $row = Notification::query()->where('reservation_id', $reservation->id)->where('channel', 'in_app')->first();
        $this->assertSame('ar', $row->locale);
        $this->assertSame('تم تأكيد مبلغ التأمين', $row->subject);
    }
}
