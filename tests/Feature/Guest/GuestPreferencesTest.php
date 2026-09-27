<?php

namespace Tests\Feature\Guest;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Services\NotificationRecipient;
use App\Domain\Reservation\Models\Guest;
use Tests\TestCase;

/**
 * PROFILE_Preferences / PROFILE_Privacy — the guest's stay preferences and
 * data-deletion request.
 */
class GuestPreferencesTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create(['email' => 'guest@example.test']);
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    public function test_preferences_default_and_merge_only_known_keys(): void
    {
        $guest = $this->actingGuest();

        $this->getJson('/api/v1/guest/auth/me')
            ->assertOk()
            ->assertJsonPath('data.guest.preferences', ['high_floor' => false, 'extra_pillows' => false, 'notifications_enabled' => true]);

        $this->patchJson('/api/v1/guest/preferences', ['high_floor' => true, 'unknown' => true])
            ->assertOk()
            ->assertJsonPath('data.guest.preferences.high_floor', true)
            ->assertJsonPath('data.guest.preferences.extra_pillows', false)
            ->assertJsonMissingPath('data.guest.preferences.unknown');

        $this->patchJson('/api/v1/guest/preferences', ['extra_pillows' => true])
            ->assertOk()
            ->assertJsonPath('data.guest.preferences.high_floor', true)
            ->assertJsonPath('data.guest.preferences.extra_pillows', true);

        $this->patchJson('/api/v1/guest/preferences', ['high_floor' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('high_floor');

        $this->assertTrue($guest->fresh()->resolvedPreferences()['high_floor']);
    }

    public function test_opting_out_stops_email_and_sms_but_not_in_app(): void
    {
        $guest = $this->actingGuest();
        $this->patchJson('/api/v1/guest/preferences', ['notifications_enabled' => false])->assertOk();

        $recipient = NotificationRecipient::fromGuest($guest->fresh());
        $this->assertTrue($recipient->canReceiveOn(NotificationChannel::InApp));
        $this->assertFalse($recipient->canReceiveOn(NotificationChannel::Email));
        $this->assertFalse($recipient->canReceiveOn(NotificationChannel::Sms));
    }

    public function test_data_deletion_request_is_recorded_once_and_visible_to_staff(): void
    {
        $guest = $this->actingGuest();

        $first = $this->postJson('/api/v1/guest/privacy/deletion-request')
            ->assertOk()
            ->json('data.guest.data_deletion_requested_at');
        $this->assertNotNull($first);

        $this->travel(5)->minutes();
        $this->postJson('/api/v1/guest/privacy/deletion-request')
            ->assertOk()
            ->assertJsonPath('data.guest.data_deletion_requested_at', $first);

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->groupOwner()->create(), 'sanctum')
            ->getJson("/api/v1/guests/{$guest->id}")
            ->assertOk()
            ->assertJsonPath('data.data_deletion_requested_at', $first)
            ->assertJsonPath('data.preferences.notifications_enabled', true);
    }

    public function test_preferences_require_a_guest_token(): void
    {
        $this->patchJson('/api/v1/guest/preferences', ['high_floor' => true])->assertStatus(401);
        $this->postJson('/api/v1/guest/privacy/deletion-request')->assertStatus(401);
    }
}
