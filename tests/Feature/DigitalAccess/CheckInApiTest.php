<?php

namespace Tests\Feature\DigitalAccess;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CheckInApiTest extends TestCase
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

    private function checkIn(User $actor, Reservation $reservation, array $headers = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->withHeaders($headers)
            ->postJson("/api/v1/check-in/{$reservation->id}");
    }

    // ── Authentication / Authorization ──────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $reservation = $this->verifiedReservation();

        $this->postJson("/api/v1/check-in/{$reservation->id}")->assertStatus(401);
        $this->assertDatabaseCount('access_grants', 0);
    }

    public function test_group_owner_and_reception_can_check_in(): void
    {
        $this->checkIn($this->owner(), $this->verifiedReservation())->assertStatus(201);

        $hotel = Hotel::factory()->create();
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $this->checkIn($reception, $this->verifiedReservation($hotel))->assertStatus(201);
    }

    public function test_guest_role_user_gets_a_scope_404(): void
    {
        $this->checkIn(User::factory()->guest()->create(), $this->verifiedReservation())->assertStatus(404);
        $this->assertDatabaseCount('access_grants', 0);
    }

    public function test_manager_in_an_unassigned_hotel_gets_a_scope_404(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $this->checkIn($manager, $this->verifiedReservation($other))->assertStatus(404);
    }

    public function test_client_supplied_hotel_id_is_ignored(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->verifiedReservation($hotel);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/check-in/{$reservation->id}", ['hotel_id' => Hotel::factory()->create()->id])
            ->assertStatus(201)
            ->assertJsonPath('data.hotel_id', $hotel->id);
    }

    // ── Happy path ─────────────────────────────────────────────────

    public function test_check_in_issues_a_credential_and_moves_the_reservation_to_checked_in(): void
    {
        $reservation = $this->verifiedReservation();

        $this->checkIn($this->owner(), $reservation, ['X-Digital-Access-Simulate' => 'success'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', AccessGrant::STATUS_ACTIVE)
            ->assertJsonPath('data.access_mode', 'pin_code')
            ->assertJsonStructure(['data' => ['credential', 'expires_at']]);

        $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);
    }

    // ── Wrong state / eligibility ─────────────────────────────────

    public function test_check_in_on_a_non_verified_reservation_is_a_422(): void
    {
        $reservation = $this->verifiedReservation();
        $reservation->update(['status' => Reservation::STATUS_DEPOSIT_HELD]);

        $this->checkIn($this->owner(), $reservation)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertDatabaseCount('access_grants', 0);
    }

    public function test_check_in_blocked_when_payment_hold_not_active_is_a_422(): void
    {
        $reservation = $this->verifiedReservation();
        $reservation->payment->update(['status' => Payment::STATUS_EXPIRED]);

        $this->checkIn($this->owner(), $reservation)->assertStatus(422);
    }

    public function test_provider_failure_is_a_422_and_leaves_the_reservation_verified(): void
    {
        $reservation = $this->verifiedReservation();

        $this->checkIn($this->owner(), $reservation, ['X-Digital-Access-Simulate' => 'failure'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status', AccessGrant::STATUS_FAILED);

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
        $this->assertSame(AccessGrant::STATUS_FAILED, AccessGrant::sole()->status);
    }

    // ── Idempotency ───────────────────────────────────────────────

    public function test_same_idempotency_key_replays_without_a_new_grant(): void
    {
        $reservation = $this->verifiedReservation();
        $owner = $this->owner();
        $headers = ['Idempotency-Key' => 'ci-key-1'];

        $first = $this->checkIn($owner, $reservation, $headers)->assertStatus(201);
        $second = $this->checkIn($owner, $reservation->fresh(), $headers)->assertStatus(201);

        $this->assertSame($first->json('data.credential'), $second->json('data.credential'));
        $this->assertDatabaseCount('access_grants', 1);
    }

    public function test_malformed_idempotency_key_is_a_422(): void
    {
        $this->checkIn($this->owner(), $this->verifiedReservation(), ['Idempotency-Key' => 'has spaces'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    // ── Security ──────────────────────────────────────────────────

    public function test_response_never_exposes_provider_reference_or_idempotency_key(): void
    {
        $reservation = $this->verifiedReservation();
        $body = $this->checkIn($this->owner(), $reservation, ['X-Digital-Access-Simulate' => 'success'])->getContent();

        foreach (['provider_reference', 'idempotency_key', 'metadata', 'DummyDigitalAccessProvider', 'SQLSTATE', '.php:'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "leaked '{$needle}'");
        }
    }

    public function test_simulation_header_is_ignored_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // Default directive is success; the header must not force a failure.
        $this->checkIn($this->owner(), $this->verifiedReservation(), ['X-Digital-Access-Simulate' => 'failure'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', AccessGrant::STATUS_ACTIVE);
    }

    public function test_unknown_simulation_directive_is_a_422_in_testing(): void
    {
        $this->checkIn($this->owner(), $this->verifiedReservation(), ['X-Digital-Access-Simulate' => 'boom'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['simulate']);
    }
}
