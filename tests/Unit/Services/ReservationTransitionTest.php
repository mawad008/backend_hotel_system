<?php

namespace Tests\Unit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\EloquentRoomRepository;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use App\Domain\Reservation\Exceptions\InvalidReservationStatusTransitionException;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\EloquentGuestRepository;
use App\Domain\Reservation\Repositories\EloquentReservationRepository;
use App\Domain\Reservation\Services\ReservationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4B — ReservationService::transitionTo. Structural transition rules
 * are proved in ReservationStateMachineTest; this suite proves the service
 * executes them under a transaction + row lock, persists through the
 * repository, and writes the reservation.status_changed audit entry.
 */
class ReservationTransitionTest extends TestCase
{
    private ReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReservationService(
            new EloquentReservationRepository,
            new EloquentRoomTypeRepository,
            new EloquentRoomRepository,
            new EloquentGuestRepository,
            app(AuditLogger::class),
        );
    }

    private function reservationInStatus(string $status): Reservation
    {
        return Reservation::factory()->create(['status' => $status]);
    }

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
    public function test_approved_transition_persists_the_new_status(string $from, string $to): void
    {
        $reservation = $this->reservationInStatus($from);
        $actor = User::factory()->groupOwner()->create();

        $result = $this->service->transitionTo($reservation, $to, $actor);

        $this->assertSame($to, $result->status);
        $this->assertSame($to, $reservation->fresh()->status);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'PENDING -> VERIFIED' => ['pending', 'verified'],
            'DEPOSIT_HELD -> CHECKED_IN' => ['deposit_held', 'checked_in'],
            'CHECKED_IN -> CANCELLED' => ['checked_in', 'cancelled'],
            'IN_STAY -> CANCELLED' => ['in_stay', 'cancelled'],
            'terminal INVOICED -> anything' => ['invoiced', 'cancelled'],
            'terminal CANCELLED -> anything' => ['cancelled', 'pending'],
            'CHECKOUT_BLOCKED has no approved outgoing transition' => ['checkout_blocked', 'checked_out'],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_throws_and_changes_nothing(string $from, string $to): void
    {
        $reservation = $this->reservationInStatus($from);
        $actor = User::factory()->groupOwner()->create();

        try {
            $this->service->transitionTo($reservation, $to, $actor);
            $this->fail("Expected {$from} -> {$to} to be rejected.");
        } catch (InvalidReservationStatusTransitionException $e) {
            $this->assertSame($from, $e->from);
            $this->assertSame($to, $e->to);
        }

        $this->assertSame($from, $reservation->fresh()->status);
        $this->assertNull(AuditLog::where('action', 'reservation.status_changed')->first());
    }

    public function test_successful_transition_writes_a_status_changed_audit_entry(): void
    {
        $reservation = $this->reservationInStatus(Reservation::STATUS_PENDING);
        $actor = User::factory()->groupOwner()->create();

        $this->service->transitionTo($reservation, Reservation::STATUS_DEPOSIT_HELD, $actor);

        $log = AuditLog::where('action', 'reservation.status_changed')->first();

        $this->assertNotNull($log);
        $this->assertSame($reservation->id, $log->auditable_id);
        $this->assertSame($reservation->hotel_id, $log->hotel_id);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame(Reservation::STATUS_PENDING, $log->before['status']);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $log->after['status']);
    }

    public function test_actor_is_optional(): void
    {
        $reservation = $this->reservationInStatus(Reservation::STATUS_PENDING);

        $result = $this->service->transitionTo($reservation, Reservation::STATUS_CANCELLED);

        $this->assertSame(Reservation::STATUS_CANCELLED, $result->status);
        $this->assertNull(AuditLog::where('action', 'reservation.status_changed')->first()->actor_id);
    }

    /**
     * The passed model carries a stale PENDING status while the row has
     * already advanced to DEPOSIT_HELD. transitionTo must decide from the
     * locked re-read (DEPOSIT_HELD -> VERIFIED is valid), not the stale
     * model (PENDING -> VERIFIED would throw).
     */
    public function test_transition_uses_the_locked_reread_not_the_stale_model_status(): void
    {
        $reservation = $this->reservationInStatus(Reservation::STATUS_PENDING);
        $actor = User::factory()->groupOwner()->create();

        // Simulate a concurrent transition already committed by another request.
        Reservation::whereKey($reservation->id)->update(['status' => Reservation::STATUS_DEPOSIT_HELD]);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->status); // in-memory model is stale

        $result = $this->service->transitionTo($reservation, Reservation::STATUS_VERIFIED, $actor);

        $this->assertSame(Reservation::STATUS_VERIFIED, $result->status);

        $log = AuditLog::where('action', 'reservation.status_changed')->first();
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $log->before['status']);
    }

    public function test_stale_model_cannot_force_an_invalid_transition_past_the_reread(): void
    {
        $reservation = $this->reservationInStatus(Reservation::STATUS_DEPOSIT_HELD);
        $actor = User::factory()->groupOwner()->create();

        // Row was actually cancelled by a concurrent request.
        Reservation::whereKey($reservation->id)->update(['status' => Reservation::STATUS_CANCELLED]);

        $this->expectException(InvalidReservationStatusTransitionException::class);

        $this->service->transitionTo($reservation, Reservation::STATUS_VERIFIED, $actor);
    }

    /**
     * If the audit write fails, the whole transaction rolls back and the
     * status is left untouched.
     */
    public function test_transaction_rolls_back_when_the_audit_write_fails(): void
    {
        $throwingAudit = new class extends AuditLogger
        {
            public function __construct() {}

            public function record(?User $actor, string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?int $hotelId = null): AuditLog
            {
                throw new RuntimeException('audit failure');
            }
        };

        $service = new ReservationService(
            new EloquentReservationRepository,
            new EloquentRoomTypeRepository,
            new EloquentRoomRepository,
            new EloquentGuestRepository,
            $throwingAudit,
        );

        $reservation = $this->reservationInStatus(Reservation::STATUS_PENDING);

        try {
            $service->transitionTo($reservation, Reservation::STATUS_DEPOSIT_HELD, null);
            $this->fail('Expected the audit failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit failure', $e->getMessage());
        }

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_transition_on_a_deleted_reservation_throws_model_not_found(): void
    {
        $reservation = $this->reservationInStatus(Reservation::STATUS_PENDING);
        $id = $reservation->id;
        Reservation::whereKey($id)->delete();

        $this->expectException(ModelNotFoundException::class);

        $this->service->transitionTo($reservation, Reservation::STATUS_DEPOSIT_HELD, null);
    }

    public function test_reservation_creation_is_unchanged_and_still_starts_pending(): void
    {
        $roomType = RoomType::factory()->create();
        Room::factory()->create([
            'hotel_id' => $roomType->hotel_id,
            'room_type_id' => $roomType->id,
        ]);
        $guest = Guest::factory()->create();

        $reservation = $this->service->create([
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], null);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->status);
    }
}
