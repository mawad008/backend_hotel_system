<?php

namespace Tests\Feature\DigitalAccess;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class DigitalAccessApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function verifiedReservation(?Hotel $hotel = null): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->withRoom()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_VERIFIED,
            'check_out' => now()->addDays(3)->format('Y-m-d'),
        ]);
        Payment::factory()->holdActive()->create(['reservation_id' => $reservation->id, 'hotel_id' => $hotel->id]);
        IdentityVerificationSession::factory()->autoApproved()->create(['reservation_id' => $reservation->id]);

        return $reservation;
    }

    private function checkIn(User $actor, Reservation $reservation): void
    {
        $this->actingAs($actor, 'sanctum')->postJson("/api/v1/check-in/{$reservation->id}")->assertStatus(201);
    }

    // ── Status endpoint ───────────────────────────────────────────

    public function test_status_before_check_in_is_not_issued(): void
    {
        $reservation = $this->verifiedReservation();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/access/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.status', AccessGrant::STATUS_NOT_ISSUED)
            ->assertJsonMissingPath('data.credential');
    }

    public function test_status_after_check_in_returns_the_active_grant_with_the_pin(): void
    {
        $reservation = $this->verifiedReservation();
        $this->checkIn($this->owner(), $reservation);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/access/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.status', AccessGrant::STATUS_ACTIVE)
            ->assertJsonStructure(['data' => ['credential']]);
    }

    public function test_status_lazily_expires_a_grant_past_its_window(): void
    {
        $reservation = $this->verifiedReservation();
        $this->checkIn($this->owner(), $reservation);
        AccessGrant::query()->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/access/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.status', AccessGrant::STATUS_EXPIRED)
            ->assertJsonMissingPath('data.credential');
    }

    public function test_status_401_403_404(): void
    {
        $reservation = $this->verifiedReservation();

        $this->getJson("/api/v1/access/{$reservation->id}")->assertStatus(401);

        $this->actingAs(User::factory()->guest()->create(), 'sanctum')
            ->getJson("/api/v1/access/{$reservation->id}")->assertStatus(404);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/access/999999')->assertStatus(404);
    }

    // ── Revoke endpoint ───────────────────────────────────────────

    public function test_revoke_kills_the_credential(): void
    {
        $reservation = $this->verifiedReservation();
        $this->checkIn($this->owner(), $reservation);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/access/{$reservation->id}/revoke", ['reason' => 'lost device'])
            ->assertOk()
            ->assertJsonPath('data.status', AccessGrant::STATUS_REVOKED)
            ->assertJsonMissingPath('data.credential');

        // Reservation stays CHECKED_IN.
        $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);
    }

    public function test_repeated_revoke_is_idempotent_200(): void
    {
        $reservation = $this->verifiedReservation();
        $this->checkIn($this->owner(), $reservation);

        $this->actingAs($this->owner(), 'sanctum')->postJson("/api/v1/access/{$reservation->id}/revoke")->assertOk();
        $this->actingAs($this->owner(), 'sanctum')->postJson("/api/v1/access/{$reservation->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', AccessGrant::STATUS_REVOKED);
    }

    public function test_revoke_before_check_in_is_a_422(): void
    {
        $reservation = $this->verifiedReservation();

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/access/{$reservation->id}/revoke")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_revoke_unauthenticated_is_401(): void
    {
        $reservation = $this->verifiedReservation();

        $this->postJson("/api/v1/access/{$reservation->id}/revoke")->assertStatus(401);
    }

    public function test_revoke_guest_role_is_scope_404(): void
    {
        $reservation = $this->verifiedReservation();
        $this->checkIn($this->owner(), $reservation);

        $this->actingAs(User::factory()->guest()->create(), 'sanctum')
            ->postJson("/api/v1/access/{$reservation->id}/revoke")->assertStatus(404);
    }

    public function test_provider_revoke_failure_still_revokes_and_returns_200(): void
    {
        $reservation = $this->verifiedReservation();
        $this->checkIn($this->owner(), $reservation);

        $this->actingAs($this->owner(), 'sanctum')
            ->withHeaders(['X-Digital-Access-Simulate' => 'failure'])
            ->postJson("/api/v1/access/{$reservation->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', AccessGrant::STATUS_REVOKED);
    }
}
