<?php

namespace Tests\Feature\Notification;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\Notification;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function reservation(?Hotel $hotel = null): Reservation
    {
        $hotel ??= Hotel::factory()->create();

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ]);
    }

    private function seedFeed(Reservation $reservation, int $inApp = 3, int $email = 2): void
    {
        Notification::factory()->count($inApp)->forReservation($reservation)
            ->channel(NotificationChannel::InApp)->create();
        Notification::factory()->count($email)->forReservation($reservation)
            ->channel(NotificationChannel::Email)->create();
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson("/api/v1/reservations/{$this->reservation()->id}/notifications")->assertStatus(401);
    }

    public function test_guest_role_gets_a_scope_404(): void
    {
        $this->actingAs(User::factory()->guest()->create(), 'sanctum')
            ->getJson("/api/v1/reservations/{$this->reservation()->id}/notifications")
            ->assertStatus(404);
    }

    public function test_cross_hotel_reservation_is_a_plain_404(): void
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach(Hotel::factory()->create());

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reservations/{$this->reservation()->id}/notifications")
            ->assertStatus(404);
    }

    public function test_index_returns_only_the_in_app_channel_newest_first(): void
    {
        $reservation = $this->reservation();
        $this->seedFeed($reservation, inApp: 3, email: 2);

        $response = $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/notifications")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'type', 'channel', 'status', 'subject', 'body', 'is_read', 'read_at']], 'meta']);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame($ids, collect($ids)->sortDesc()->values()->all());

        foreach ($response->json('data') as $row) {
            $this->assertSame('in_app', $row['channel']);
        }
    }

    public function test_index_paginates(): void
    {
        $reservation = $this->reservation();
        $this->seedFeed($reservation, inApp: 7, email: 0);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/notifications?per_page=3")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.per_page', 3);
    }

    public function test_index_can_filter_to_unread_only(): void
    {
        $reservation = $this->reservation();
        Notification::factory()->count(2)->forReservation($reservation)->channel(NotificationChannel::InApp)->create();
        Notification::factory()->count(3)->forReservation($reservation)->channel(NotificationChannel::InApp)->read()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/notifications?unread=1")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_mark_read_sets_read_at_and_is_idempotent(): void
    {
        $reservation = $this->reservation();
        $row = Notification::factory()->forReservation($reservation)->channel(NotificationChannel::InApp)->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/reservations/{$reservation->id}/notifications/{$row->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $stamp = $row->fresh()->read_at;

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/reservations/{$reservation->id}/notifications/{$row->id}/read")
            ->assertOk();

        $this->assertEquals($stamp, $row->fresh()->read_at);
    }

    public function test_mark_read_on_an_email_row_is_a_422(): void
    {
        $reservation = $this->reservation();
        $row = Notification::factory()->forReservation($reservation)->channel(NotificationChannel::Email)->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/reservations/{$reservation->id}/notifications/{$row->id}/read")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_mark_read_for_a_notification_of_another_reservation_is_a_404(): void
    {
        $reservation = $this->reservation();
        $other = $this->reservation();
        $row = Notification::factory()->forReservation($other)->channel(NotificationChannel::InApp)->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/reservations/{$reservation->id}/notifications/{$row->id}/read")
            ->assertStatus(404);
    }

    public function test_mark_all_read_clears_only_this_reservations_unread_in_app_rows(): void
    {
        $reservation = $this->reservation();
        $other = $this->reservation();
        Notification::factory()->count(3)->forReservation($reservation)->channel(NotificationChannel::InApp)->create();
        Notification::factory()->count(2)->forReservation($reservation)->channel(NotificationChannel::Email)->create();
        Notification::factory()->count(4)->forReservation($other)->channel(NotificationChannel::InApp)->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/notifications/read-all")
            ->assertOk()
            ->assertJsonPath('data.marked_read', 3);

        $this->assertSame(0, Notification::query()->where('reservation_id', $reservation->id)->where('channel', 'in_app')->whereNull('read_at')->count());
        $this->assertSame(4, Notification::query()->where('reservation_id', $other->id)->whereNull('read_at')->count());
        // email rows are untouched.
        $this->assertSame(2, Notification::query()->where('reservation_id', $reservation->id)->where('channel', 'email')->whereNull('read_at')->count());
    }

    public function test_the_feed_never_leaks_the_idempotency_key_or_provider_reference(): void
    {
        $reservation = $this->reservation();
        Notification::factory()->forReservation($reservation)->channel(NotificationChannel::InApp)->create();

        $body = $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/notifications")
            ->getContent();

        $this->assertStringNotContainsString('idempotency_key', $body);
        $this->assertStringNotContainsString('reservation:', $body);
    }
}
