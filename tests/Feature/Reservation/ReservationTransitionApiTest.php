<?php

namespace Tests\Feature\Reservation;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 4C — POST /api/v1/reservations/{reservation}/transition.
 * Proves the HTTP / auth / policy / request / response integration around
 * the existing Phase 4B ReservationService::transitionTo. The transition
 * rules themselves are covered by ReservationStateMachineTest /
 * ReservationTransitionTest and are not re-proved exhaustively here.
 */
class ReservationTransitionApiTest extends TestCase
{
    private function reservation(string $status, ?Hotel $hotel = null): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => $status,
        ]);
    }

    private function transition(User $actor, Reservation $reservation, array $body): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/transition", $body);
    }

    // ── Approved transitions ───────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function approvedTransitions(): array
    {
        return [
            'PENDING -> DEPOSIT_HELD' => ['pending', 'deposit_held'],
            'PENDING -> CANCELLED' => ['pending', 'cancelled'],
            'DEPOSIT_HELD -> VERIFIED' => ['deposit_held', 'verified'],
            'DEPOSIT_HELD -> CANCELLED' => ['deposit_held', 'cancelled'],
            'VERIFIED -> CHECKED_IN' => ['verified', 'checked_in'],
            'VERIFIED -> CANCELLED' => ['verified', 'cancelled'],
            'CHECKED_IN -> IN_STAY' => ['checked_in', 'in_stay'],
            'IN_STAY -> CHECKOUT_IN_PROGRESS' => ['in_stay', 'checkout_in_progress'],
            'CHECKOUT_IN_PROGRESS -> CHECKED_OUT' => ['checkout_in_progress', 'checked_out'],
            'CHECKOUT_IN_PROGRESS -> CHECKOUT_BLOCKED' => ['checkout_in_progress', 'checkout_blocked'],
            'CHECKED_OUT -> INVOICED' => ['checked_out', 'invoiced'],
        ];
    }

    #[DataProvider('approvedTransitions')]
    public function test_authorized_user_can_perform_approved_transition(string $from, string $to): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservation($from);

        $this->transition($owner, $reservation, ['target_status' => $to])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.status', $to);

        $this->assertSame($to, $reservation->fresh()->status);
    }

    public function test_successful_transition_writes_a_status_changed_audit_entry(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->transition($owner, $reservation, ['target_status' => Reservation::STATUS_DEPOSIT_HELD])
            ->assertOk();

        $log = AuditLog::where('action', 'reservation.status_changed')->first();

        $this->assertNotNull($log);
        $this->assertSame($reservation->id, $log->auditable_id);
        $this->assertSame($reservation->hotel_id, $log->hotel_id);
        $this->assertSame($owner->id, $log->actor_id);
        $this->assertSame(Reservation::STATUS_PENDING, $log->before['status']);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $log->after['status']);
    }

    // ── Invalid / terminal transitions → 422 business envelope ──────

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedTransitions(): array
    {
        return [
            'invalid: PENDING -> VERIFIED' => ['pending', 'verified'],
            'invalid: CHECKED_IN -> CANCELLED' => ['checked_in', 'cancelled'],
            'terminal: INVOICED -> CANCELLED' => ['invoiced', 'cancelled'],
            'terminal: CANCELLED -> PENDING' => ['cancelled', 'pending'],
            'no outgoing: CHECKOUT_BLOCKED -> CHECKED_OUT' => ['checkout_blocked', 'checked_out'],
        ];
    }

    #[DataProvider('rejectedTransitions')]
    public function test_rejected_transition_returns_422_business_envelope(string $from, string $to): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservation($from);

        $this->transition($owner, $reservation, ['target_status' => $to])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message'])
            ->assertJsonPath('success', false)
            // business error, not a field-validation error
            ->assertJsonMissingPath('errors.target_status');

        $this->assertSame($from, $reservation->fresh()->status);
    }

    // ── Request validation ─────────────────────────────────────────

    public function test_missing_target_status_returns_validation_422(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->transition($owner, $reservation, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_status']);
    }

    public function test_unknown_target_status_returns_validation_422(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->transition($owner, $reservation, ['target_status' => 'teleported'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_status']);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_non_string_target_status_returns_validation_422(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->transition($owner, $reservation, ['target_status' => ['deposit_held']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_status']);
    }

    // ── Hotel scope / server-controlled fields ─────────────────────

    public function test_client_supplied_hotel_id_and_actor_are_ignored(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING, $hotel);

        $this->transition($owner, $reservation, [
            'target_status' => Reservation::STATUS_DEPOSIT_HELD,
            'hotel_id' => $otherHotel->id,
            'created_by_staff_id' => 999999,
            'guest_id' => 999999,
            'current_status' => Reservation::STATUS_INVOICED,
        ])->assertOk()
            ->assertJsonPath('data.hotel_id', $hotel->id)
            ->assertJsonPath('data.status', Reservation::STATUS_DEPOSIT_HELD);

        $fresh = $reservation->fresh();
        $this->assertSame($hotel->id, $fresh->hotel_id);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $fresh->status);

        $log = AuditLog::where('action', 'reservation.status_changed')->first();
        $this->assertSame($owner->id, $log->actor_id);
    }

    public function test_cross_hotel_reservation_returns_404(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING, $hotelB);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $this->transition($manager, $reservation, ['target_status' => Reservation::STATUS_DEPOSIT_HELD])
            ->assertStatus(404);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_nonexistent_reservation_returns_404(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reservations/999999/transition', ['target_status' => 'deposit_held'])
            ->assertStatus(404);
    }

    // ── Authentication / authorization ─────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->postJson("/api/v1/reservations/{$reservation->id}/transition", ['target_status' => 'deposit_held'])
            ->assertStatus(401);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_reception_lacking_reservations_manage_is_forbidden(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING, $hotel);

        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->transition($reception, $reservation, ['target_status' => Reservation::STATUS_DEPOSIT_HELD])
            ->assertStatus(403);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    /**
     * A Guest-role user has no hotel access at all, so the reservation is
     * resolved out of scope and returns the same clean 404 as any other
     * inaccessible id — the endpoint never leaks that it exists. (The
     * permission-denied-but-visible case is covered by the Reception test
     * above, which returns 403.)
     */
    public function test_guest_user_cannot_reach_the_endpoint(): void
    {
        $reservation = $this->reservation(Reservation::STATUS_PENDING);
        $guest = User::factory()->guest()->create();

        $this->transition($guest, $reservation, ['target_status' => Reservation::STATUS_DEPOSIT_HELD])
            ->assertStatus(404);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_manager_can_transition_in_assigned_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING, $hotel);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->transition($manager, $reservation, ['target_status' => Reservation::STATUS_CANCELLED])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CANCELLED);
    }

    public function test_manager_cannot_transition_in_unassigned_hotel_via_scope_404(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING, $other);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        // Resolved out of scope before authorization is even reached → 404, not 403.
        $this->transition($manager, $reservation, ['target_status' => Reservation::STATUS_DEPOSIT_HELD])
            ->assertStatus(404);
    }

    // ── Response contract ──────────────────────────────────────────

    public function test_response_returns_the_full_reservation_resource(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->transition($owner, $reservation, ['target_status' => Reservation::STATUS_DEPOSIT_HELD])
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => [
                'id', 'hotel_id', 'room_type_id', 'room_id', 'guest_id',
                'check_in', 'check_out', 'status', 'price_snapshot',
                'created_by_staff_id', 'cancelled_at', 'cancellation_reason',
                'created_at', 'updated_at',
            ]]);
    }
}
