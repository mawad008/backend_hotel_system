<?php

namespace Tests\Unit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\EloquentRoomRepository;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use App\Domain\Reservation\Exceptions\ReservationNotAvailableException;
use App\Domain\Reservation\Exceptions\RoomHotelMismatchException;
use App\Domain\Reservation\Exceptions\RoomTypeMismatchException;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\EloquentGuestRepository;
use App\Domain\Reservation\Repositories\EloquentReservationRepository;
use App\Domain\Reservation\Services\ReservationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 3D availability + concurrency protection, approved decisions:
 * - Blocking statuses: every status except CANCELLED (Reservation::BLOCKING_STATUSES).
 * - Room-Type capacity: Option A — physical room count minus ALL
 *   overlapping blocking reservations (assigned + unassigned).
 * - Canonical overlap predicate: existing.check_in < new.check_out AND
 *   new.check_in < existing.check_out (adjacent stays allowed).
 *
 * Concurrency note (item 20): true parallel-request testing is
 * impractical in this project's PHPUnit environment — tests run against
 * a single database connection inside one process, and PHPUnit has no
 * built-in mechanism to open a second, genuinely concurrent connection
 * mid-test to race against the first while a lock is held. This is
 * documented, not worked around: the tests below are sequential
 * regression tests that deterministically prove the lock-then-recount
 * logic rejects a conflicting second request made *after* the first
 * commits — they prove the counting/locking code path is correct, not
 * that two real simultaneous connections can never race. See the final
 * Phase 3D report for this limitation stated explicitly.
 */
class ReservationAvailabilityTest extends TestCase
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

    private function create(RoomType $roomType, Guest $guest, array $overrides = []): Reservation
    {
        return $this->service->create(array_merge([
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ], $overrides), User::factory()->groupOwner()->create());
    }

    // ── 1-5: specific-room overlap semantics ────────────────────────

    public function test_specific_room_is_available_when_no_other_reservation_exists(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();

        $reservation = $this->create($roomType, $guest, ['room_id' => $room->id]);

        $this->assertSame($room->id, $reservation->room_id);
    }

    public function test_specific_room_with_an_overlapping_blocking_reservation_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA, ['room_id' => $room->id, 'check_in' => '2026-10-10', 'check_out' => '2026-10-15']);

        $this->expectException(ReservationNotAvailableException::class);

        $this->create($roomType, $guestB, ['room_id' => $room->id, 'check_in' => '2026-10-12', 'check_out' => '2026-10-18']);
    }

    public function test_specific_room_adjacent_reservation_is_allowed(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA, ['room_id' => $room->id, 'check_in' => '2026-10-10', 'check_out' => '2026-10-12']);

        $reservation = $this->create($roomType, $guestB, ['room_id' => $room->id, 'check_in' => '2026-10-12', 'check_out' => '2026-10-15']);

        $this->assertSame($room->id, $reservation->room_id);
    }

    public function test_specific_room_exact_same_range_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA, ['room_id' => $room->id, 'check_in' => '2026-10-10', 'check_out' => '2026-10-15']);

        $this->expectException(ReservationNotAvailableException::class);

        $this->create($roomType, $guestB, ['room_id' => $room->id, 'check_in' => '2026-10-10', 'check_out' => '2026-10-15']);
    }

    public function test_specific_room_partial_overlap_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA, ['room_id' => $room->id, 'check_in' => '2026-10-10', 'check_out' => '2026-10-15']);

        $this->expectException(ReservationNotAvailableException::class);

        // Partial overlap at the beginning.
        $this->create($roomType, $guestB, ['room_id' => $room->id, 'check_in' => '2026-10-08', 'check_out' => '2026-10-12']);
    }

    // ── 6-10: Room-Type (Option A) capacity ─────────────────────────

    public function test_unassigned_room_type_reservation_is_available_within_capacity(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();

        $reservation = $this->create($roomType, $guest);

        $this->assertNull($reservation->room_id);
        $this->assertSame($roomType->id, $reservation->room_type_id);
    }

    public function test_room_type_capacity_exhausted_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA); // consumes the type's only room

        $this->expectException(ReservationNotAvailableException::class);

        $this->create($roomType, $guestB);
    }

    public function test_assigned_reservations_count_toward_room_type_capacity(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA, ['room_id' => $room->id]);

        // Only 1 physical room, already consumed by an *assigned*
        // reservation — a new unassigned request for the same dates must
        // also be rejected (this is the cross-path race Phase 3D closes).
        $this->expectException(ReservationNotAvailableException::class);

        $this->create($roomType, $guestB);
    }

    public function test_unassigned_reservations_count_toward_room_type_capacity(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA); // unassigned, consumes the type's only room

        // A new *assigned* request for the same (only) room must also be
        // rejected — an existing unassigned reservation already counts
        // against that room's type-level capacity.
        $this->expectException(ReservationNotAvailableException::class);

        $this->create($roomType, $guestB, ['room_id' => $room->id]);
    }

    public function test_mixed_assigned_and_unassigned_reservations_are_counted_together(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $rooms = Room::factory()->count(3)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $guestC = Guest::factory()->create();
        $guestD = Guest::factory()->create();

        $this->create($roomType, $guestA, ['room_id' => $rooms[0]->id]); // assigned, 1/3
        $this->create($roomType, $guestB); // unassigned, 2/3
        $this->create($roomType, $guestC); // unassigned, 3/3 — capacity now exhausted

        $this->expectException(ReservationNotAvailableException::class);

        $this->create($roomType, $guestD, ['room_id' => $rooms[1]->id]); // 4th commitment — rejected even though this specific room is free
    }

    // ── 11-12: status participation ─────────────────────────────────

    public function test_cancelled_reservation_does_not_consume_capacity(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        Reservation::factory()->cancelled()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'guest_id' => $guestA->id,
            'check_in' => '2026-10-10', 'check_out' => '2026-10-15',
        ]);

        $reservation = $this->create($roomType, $guestB);

        $this->assertSame($roomType->id, $reservation->room_type_id);
    }

    public static function blockingStatuses(): array
    {
        return array_map(fn (string $status) => [$status], Reservation::BLOCKING_STATUSES);
    }

    #[DataProvider('blockingStatuses')]
    public function test_every_approved_blocking_status_consumes_capacity(string $status): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'guest_id' => $guestA->id,
            'status' => $status, 'check_in' => '2026-10-10', 'check_out' => '2026-10-15',
        ]);

        $this->expectException(ReservationNotAvailableException::class);

        $this->create($roomType, $guestB);
    }

    // ── 13-14: isolation between Room Types / Rooms ─────────────────

    public function test_different_room_types_do_not_interfere(): void
    {
        $hotel = Hotel::factory()->create();
        $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomTypeA->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomTypeB->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomTypeA, $guestA); // exhausts Room Type A's only room

        $reservation = $this->create($roomTypeB, $guestB); // Room Type B is untouched

        $this->assertSame($roomTypeB->id, $reservation->room_type_id);
    }

    public function test_different_rooms_of_the_same_room_type_do_not_interfere_for_room_specific_booking(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $roomA = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $roomB = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA, ['room_id' => $roomA->id]);

        $reservation = $this->create($roomType, $guestB, ['room_id' => $roomB->id]);

        $this->assertSame($roomB->id, $reservation->room_id);
    }

    // ── 15-18: unaffected pre-existing guarantees ───────────────────

    public function test_cross_hotel_room_protection_remains_intact(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $foreignRoom = Room::factory()->create(['hotel_id' => $otherHotel->id]);
        $guest = Guest::factory()->create();

        $this->expectException(RoomHotelMismatchException::class);

        $this->create($roomType, $guest, ['room_id' => $foreignRoom->id]);
    }

    public function test_invalid_room_room_type_relationship_remains_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $otherRoomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $mismatchedRoom = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $otherRoomType->id]);
        $guest = Guest::factory()->create();

        $this->expectException(RoomTypeMismatchException::class);

        $this->create($roomType, $guest, ['room_id' => $mismatchedRoom->id]);
    }

    /**
     * check_out == check_in (item 17) is unaffected by Phase 3D and is
     * not re-tested here: it's enforced exclusively by
     * StoreReservationRequest's existing `after:check_in` rule (Phase
     * 3C), already covered end-to-end by
     * ReservationApiTest::test_check_out_equal_to_check_in_returns_422.
     * There is no database-level CHECK constraint on these columns (the
     * reservations migration, Phase 3A, was not modified by Phase 3D), so
     * asserting a QueryException from direct persistence would be
     * asserting behavior that does not exist — not written here to avoid
     * a false claim.
     */
    public function test_adjacent_bookings_remain_allowed_for_unassigned_room_type_reservations(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA, ['check_in' => '2026-10-10', 'check_out' => '2026-10-12']);

        $reservation = $this->create($roomType, $guestB, ['check_in' => '2026-10-12', 'check_out' => '2026-10-15']);

        $this->assertSame($roomType->id, $reservation->room_type_id);
    }

    // ── 19: transaction rollback ─────────────────────────────────────

    public function test_a_rejected_reservation_persists_nothing_and_audits_nothing(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $this->create($roomType, $guestA, ['room_id' => $room->id]);

        $countBefore = Reservation::count();

        try {
            $this->create($roomType, $guestB, ['room_id' => $room->id]);
        } catch (ReservationNotAvailableException) {
            // expected
        }

        $this->assertSame($countBefore, Reservation::count());
        $this->assertSame(1, AuditLog::where('action', 'reservation.created')->count());
    }

    // ── 20: concurrency — documented limitation + strongest available regression ──

    /**
     * See the class docblock: this is a sequential, deterministic
     * regression test, not a true concurrency test. It proves that once
     * the first request has committed, the lock-then-recount logic in
     * ReservationService::create() correctly rejects a second, later
     * request for the same last unit of Room Type capacity — the
     * scenario "two concurrent unassigned bookings for the last
     * capacity" collapses to this once serialized, which is exactly what
     * the RoomType row lock guarantees actually happens under real
     * concurrency (the second request would block on the lock, then see
     * this same rejected outcome upon re-checking after the first
     * commits).
     */
    public function test_sequential_requests_for_the_last_room_type_capacity_unit_are_correctly_serialized(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();

        $first = $this->create($roomType, $guestA);

        $this->expectException(ReservationNotAvailableException::class);

        try {
            $this->create($roomType, $guestB);
        } finally {
            $this->assertSame(1, Reservation::where('room_type_id', $roomType->id)->count());
            $this->assertTrue(Reservation::whereKey($first->id)->exists());
        }
    }
}
