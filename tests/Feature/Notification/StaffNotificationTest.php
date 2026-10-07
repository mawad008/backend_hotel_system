<?php

namespace Tests\Feature\Notification;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Models\StaffNotification;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Services\ServiceOrderService;
use Tests\TestCase;

class StaffNotificationTest extends TestCase
{
    private function staffFor(Hotel $hotel): array
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        return [$manager, $reception];
    }

    private function audit(string $action, Reservation $reservation, ?User $actor = null): void
    {
        app(AuditLogger::class)->record($actor, $action, $reservation, hotelId: $reservation->hotel_id);
    }

    // ── Fan-out ────────────────────────────────────────────────────

    public function test_an_operational_event_notifies_every_eligible_staff_user_of_that_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        [$manager, $reception] = $this->staffFor($hotel);
        $owner = User::factory()->groupOwner()->create();
        $otherManager = User::factory()->hotelManager()->create();
        $otherManager->hotels()->attach($other);
        $inactive = User::factory()->hotelManager()->inactive()->create();
        $inactive->hotels()->attach($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);

        $this->audit('reservation.created', $reservation);

        $recipients = StaffNotification::query()->pluck('user_id')->sort()->values()->all();
        $expected = collect([$manager->id, $reception->id, $owner->id])->sort()->values()->all();
        $this->assertSame($expected, $recipients);

        $row = StaffNotification::query()->where('user_id', $manager->id)->firstOrFail();
        $this->assertSame('booking_created', $row->type->value);
        $this->assertSame($hotel->id, $row->hotel_id);
        $this->assertSame($reservation->id, $row->reservation_id);
        $this->assertNull($row->read_at);
    }

    public function test_the_user_who_performed_the_action_is_not_notified(): void
    {
        $hotel = Hotel::factory()->create();
        [$manager, $reception] = $this->staffFor($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);

        $this->audit('reservation.cancelled', $reservation, $manager);

        $this->assertSame([$reception->id], StaffNotification::query()->pluck('user_id')->all());
    }

    public function test_users_without_the_notifications_permission_are_not_notified(): void
    {
        $hotel = Hotel::factory()->create();
        $role = Role::factory()->create(['slug' => 'no-notify']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $user->hotels()->attach($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);

        $this->audit('reservation.created', $reservation);

        $this->assertSame(0, StaffNotification::query()->where('user_id', $user->id)->count());
    }

    public function test_actions_outside_the_allow_list_notify_nobody(): void
    {
        $hotel = Hotel::factory()->create();
        $this->staffFor($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);

        $this->audit('reservation.room_assigned', $reservation);

        $this->assertSame(0, StaffNotification::query()->count());
    }

    public function test_a_guest_service_request_notifies_staff_and_links_the_reservation(): void
    {
        $hotel = Hotel::factory()->create();
        [$manager] = $this->staffFor($hotel);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'status' => Reservation::STATUS_CHECKED_IN,
        ]);
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);

        $order = app(ServiceOrderService::class)->create($reservation, ['service_id' => $service->id, 'quantity' => 1], null);

        $row = StaffNotification::query()->where('user_id', $manager->id)->firstOrFail();
        $this->assertSame('service_requested', $row->type->value);
        $this->assertSame($reservation->id, $row->reservation_id);
        $this->assertSame($order->id, $row->context['subject_id']);
    }

    // ── Inbox API ──────────────────────────────────────────────────

    public function test_the_inbox_lists_only_the_users_own_rows_with_an_unread_count(): void
    {
        $hotel = Hotel::factory()->create(['name' => 'Nuzul Riyadh']);
        [$manager, $reception] = $this->staffFor($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);
        $this->audit('reservation.created', $reservation);
        $this->audit('payment.settlement_failed', $reservation);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('data.0.type', 'payment_issue')
            ->assertJsonPath('data.0.needs_attention', true)
            ->assertJsonPath('data.0.hotel_name', 'Nuzul Riyadh')
            ->assertJsonPath('data.0.reservation_id', $reservation->id)
            ->assertJsonPath('data.1.type', 'booking_created')
            ->assertJsonPath('data.1.needs_attention', false);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/me/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_mark_read_and_read_all(): void
    {
        $hotel = Hotel::factory()->create();
        [$manager] = $this->staffFor($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);
        $this->audit('reservation.created', $reservation);
        $this->audit('invoice.issued', $reservation);
        $first = StaffNotification::query()->where('user_id', $manager->id)->orderBy('id')->firstOrFail();

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/me/notifications/{$first->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id);
        $this->assertNotNull($first->fresh()->read_at);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/me/notifications?unread=1')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/me/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read', 1);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/me/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_another_users_notification_is_a_404(): void
    {
        $hotel = Hotel::factory()->create();
        [$manager, $reception] = $this->staffFor($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);
        $this->audit('reservation.created', $reservation);
        $receptionRow = StaffNotification::query()->where('user_id', $reception->id)->firstOrFail();

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/me/notifications/{$receptionRow->id}/read")
            ->assertNotFound();
        $this->assertNull($receptionRow->fresh()->read_at);
    }

    public function test_losing_hotel_access_hides_that_hotels_notifications(): void
    {
        $hotel = Hotel::factory()->create();
        [$manager] = $this->staffFor($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);
        $this->audit('reservation.created', $reservation);
        $row = StaffNotification::query()->where('user_id', $manager->id)->firstOrFail();

        $manager->hotels()->detach($hotel);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.unread_count', 0);
        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/me/notifications/{$row->id}/read")
            ->assertNotFound();
    }

    public function test_the_inbox_requires_the_notifications_permission(): void
    {
        $role = Role::factory()->create(['slug' => 'no-notify']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/notifications')->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/notifications/read-all')->assertForbidden();
    }

    public function test_the_inbox_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
    }
}
