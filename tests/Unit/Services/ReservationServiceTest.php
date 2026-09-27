<?php

namespace Tests\Unit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\Contracts\RoomRepositoryInterface;
use App\Domain\Inventory\Repositories\Contracts\RoomTypeRepositoryInterface;
use App\Domain\Inventory\Repositories\EloquentRoomRepository;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use App\Domain\Reservation\Exceptions\ReservationNotAvailableException;
use App\Domain\Reservation\Exceptions\RoomHotelMismatchException;
use App\Domain\Reservation\Exceptions\RoomTypeMismatchException;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\GuestRepositoryInterface;
use App\Domain\Reservation\Repositories\EloquentGuestRepository;
use App\Domain\Reservation\Repositories\EloquentReservationRepository;
use App\Domain\Reservation\Services\ReservationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use LogicException;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
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

    public function test_create_starts_the_reservation_in_pending_regardless_of_input(): void
    {
        $roomType = RoomType::factory()->create();
        Room::factory()->create(['hotel_id' => $roomType->hotel_id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $reservation = $this->service->create([
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
            'status' => Reservation::STATUS_CHECKED_IN, // must be ignored
        ], $owner);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->status);
    }

    public function test_create_derives_price_snapshot_from_room_types_base_price(): void
    {
        $roomType = RoomType::factory()->create(['base_price' => 275.50]);
        Room::factory()->create(['hotel_id' => $roomType->hotel_id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $reservation = $this->service->create([
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
            'price_snapshot' => 1.00, // must be ignored
        ], $owner);

        $this->assertSame('1102.00', $reservation->fresh()->price_snapshot); // 4 nights × 275.50
    }

    public function test_create_derives_hotel_id_from_the_room_type_and_ignores_client_hotel_id(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $reservation = $this->service->create([
            'hotel_id' => $otherHotel->id, // must be ignored — cannot widen authorization
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);

        $this->assertSame($hotel->id, $reservation->hotel_id);
    }

    public function test_create_rejects_a_missing_room_type(): void
    {
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->expectException(ModelNotFoundException::class);

        $this->service->create([
            'room_type_id' => 999999,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);
    }

    public function test_create_accepts_a_reservation_without_room_id(): void
    {
        $roomType = RoomType::factory()->create();
        Room::factory()->create(['hotel_id' => $roomType->hotel_id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $reservation = $this->service->create([
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);

        $this->assertNull($reservation->room_id);
    }

    public function test_create_accepts_a_reservation_with_a_valid_room_id(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $reservation = $this->service->create([
            'room_type_id' => $roomType->id,
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);

        $this->assertSame($room->id, $reservation->room_id);
    }

    public function test_create_rejects_a_room_from_a_different_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $foreignRoom = Room::factory()->create(['hotel_id' => $otherHotel->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->expectException(RoomHotelMismatchException::class);

        $this->service->create([
            'room_type_id' => $roomType->id,
            'room_id' => $foreignRoom->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);
    }

    public function test_create_rejects_a_room_belonging_to_a_different_room_type(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $otherRoomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $mismatchedRoom = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $otherRoomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->expectException(RoomTypeMismatchException::class);

        $this->service->create([
            'room_type_id' => $roomType->id,
            'room_id' => $mismatchedRoom->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);
    }

    public function test_create_rejects_a_missing_guest(): void
    {
        $roomType = RoomType::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->expectException(ModelNotFoundException::class);

        $this->service->create([
            'room_type_id' => $roomType->id,
            'guest_id' => 999999,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);
    }

    public function test_create_records_the_acting_staff_member(): void
    {
        $roomType = RoomType::factory()->create();
        Room::factory()->create(['hotel_id' => $roomType->hotel_id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $manager = User::factory()->hotelManager()->create();

        $reservation = $this->service->create([
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $manager);

        $this->assertSame($manager->id, $reservation->created_by_staff_id);
    }

    public function test_create_leaves_created_by_staff_id_null_when_no_actor_is_given(): void
    {
        $roomType = RoomType::factory()->create();
        Room::factory()->create(['hotel_id' => $roomType->hotel_id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();

        $reservation = $this->service->create([
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], null);

        $this->assertNull($reservation->created_by_staff_id);
    }

    public function test_create_never_mutates_the_assigned_rooms_status(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->service->create([
            'room_type_id' => $roomType->id,
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);

        $this->assertSame('available', $room->fresh()->status);
    }

    /**
     * Phase 3D behavior change: this used to prove Phase 3B performed no
     * overlap check at all (both reservations succeeded). Availability
     * protection is now implemented — a second overlapping request for
     * the same room must be rejected. See ReservationAvailabilityTest for
     * the full Phase 3D coverage; this test is kept (inverted) as a
     * direct regression guard at the exact spot the old "no check"
     * behavior used to be documented.
     */
    public function test_create_rejects_a_second_overlapping_reservation_for_the_same_room(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guestA = Guest::factory()->create();
        $guestB = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->service->create([
            'room_type_id' => $roomType->id,
            'room_id' => $room->id,
            'guest_id' => $guestA->id,
            'check_in' => '2026-12-01',
            'check_out' => '2026-12-05',
        ], $owner);

        $this->expectException(ReservationNotAvailableException::class);

        $this->service->create([
            'room_type_id' => $roomType->id,
            'room_id' => $room->id,
            'guest_id' => $guestB->id,
            'check_in' => '2026-12-01',
            'check_out' => '2026-12-05',
        ], $owner);
    }

    public function test_create_writes_an_audit_log_entry(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $reservation = $this->service->create([
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);

        $log = AuditLog::where('action', 'reservation.created')->first();

        $this->assertNotNull($log);
        $this->assertSame($hotel->id, $log->hotel_id);
        $this->assertSame($reservation->id, $log->auditable_id);
    }

    public function test_list_accessible_by_is_scoped_to_the_users_hotel_access(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        Reservation::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelA->id])->id]);
        Reservation::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $result = $this->service->listAccessibleBy($manager);

        $this->assertSame(1, $result->total());
    }

    public function test_find_accessible_by_returns_null_outside_the_users_hotel_access(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $reservation = Reservation::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $this->assertNull($this->service->findAccessibleBy($manager, $reservation->id));
    }

    /**
     * Proves the guest lookup goes through GuestRepositoryInterface rather
     * than a direct Eloquent query: the spy returns a real Guest for an id
     * that does not exist in the database, and the reservation is created
     * with it anyway — a direct `Guest::find()` call would have returned
     * null for that id and made the create() call throw instead.
     */
    public function test_create_resolves_the_guest_through_the_repository_not_a_direct_query(): void
    {
        $roomType = RoomType::factory()->create();
        Room::factory()->create(['hotel_id' => $roomType->hotel_id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();
        $realGuest = Guest::factory()->create();

        $spy = new class($realGuest) implements GuestRepositoryInterface
        {
            public bool $wasCalled = false;

            public function __construct(private readonly Guest $guest) {}

            public function find(int $id): ?Guest
            {
                $this->wasCalled = true;

                return $this->guest;
            }

            public function findByPhone(string $phone): ?Guest
            {
                return $this->guest;
            }

            public function create(array $data): Guest
            {
                return $this->guest;
            }

            public function update(Guest $guest, array $data): Guest
            {
                return $guest;
            }

            public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
            {
                throw new \RuntimeException('not used by this test');
            }
        };

        $service = new ReservationService(
            new EloquentReservationRepository,
            new EloquentRoomTypeRepository,
            new EloquentRoomRepository,
            $spy,
            app(AuditLogger::class),
        );

        $reservation = $service->create([
            'room_type_id' => $roomType->id,
            'guest_id' => 999999, // does not exist — proves the spy, not the DB, resolved it
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);

        $this->assertTrue($spy->wasCalled);
        $this->assertSame($realGuest->id, $reservation->guest_id);
    }

    /**
     * Same proof as above, for RoomTypeRepositoryInterface: the spy
     * returns a real Room Type for an id that does not exist, and the
     * reservation is created against it.
     */
    public function test_create_resolves_the_room_type_through_the_repository_not_a_direct_query(): void
    {
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();
        $realRoomType = RoomType::factory()->create(['base_price' => 123.45]);
        Room::factory()->create(['hotel_id' => $realRoomType->hotel_id, 'room_type_id' => $realRoomType->id]);

        $spy = new class($realRoomType) implements RoomTypeRepositoryInterface
        {
            public bool $wasCalled = false;

            public function __construct(private readonly RoomType $roomType) {}

            public function paginateAccessibleBy($user, $hotel, int $perPage = 15): LengthAwarePaginator
            {
                throw new LogicException('not used by this test');
            }

            public function find(int $id): ?RoomType
            {
                throw new LogicException('not used by this test');
            }

            public function findForUpdate(int $id): ?RoomType
            {
                $this->wasCalled = true;

                return $this->roomType;
            }

            public function create(array $data): RoomType
            {
                throw new LogicException('not used by this test');
            }

            public function update(RoomType $roomType, array $data): RoomType
            {
                throw new LogicException('not used by this test');
            }
        };

        $service = new ReservationService(
            new EloquentReservationRepository,
            $spy,
            new EloquentRoomRepository,
            new EloquentGuestRepository,
            app(AuditLogger::class),
        );

        $reservation = $service->create([
            'room_type_id' => 999999, // does not exist — proves the spy, not the DB, resolved it
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);

        $this->assertTrue($spy->wasCalled);
        $this->assertSame('493.80', $reservation->price_snapshot); // 4 nights × the spy's 123.45
    }

    /**
     * Same proof as above, for RoomRepositoryInterface: the spy returns a
     * real Room (matching hotel/room type) for an id that does not exist.
     */
    public function test_create_resolves_the_room_through_the_repository_not_a_direct_query(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $realRoom = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $guest = Guest::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $spy = new class($realRoom) implements RoomRepositoryInterface
        {
            public bool $wasCalled = false;

            public function __construct(private readonly Room $room) {}

            public function paginateAccessibleBy($user, $hotel, ?int $roomTypeId = null, int $perPage = 15): LengthAwarePaginator
            {
                throw new LogicException('not used by this test');
            }

            public function find(int $id): ?Room
            {
                throw new LogicException('not used by this test');
            }

            public function findForUpdate(int $id): ?Room
            {
                $this->wasCalled = true;

                return $this->room;
            }

            public function create(array $data): Room
            {
                throw new LogicException('not used by this test');
            }

            public function update(Room $room, array $data): Room
            {
                throw new LogicException('not used by this test');
            }

            public function countByRoomType(int $roomTypeId): int
            {
                // Not the method under test here, but the Service's Option
                // A capacity check still calls it unconditionally — must
                // return a real count (1, matching $realRoom) rather than
                // throw, or create() would fail for an unrelated reason.
                return 1;
            }

            public function countByHotel(int $hotelId): int
            {
                throw new LogicException('not used by this test');
            }

            public function allForRoomType(int $roomTypeId): Collection
            {
                throw new LogicException('not used by this test');
            }
        };

        $service = new ReservationService(
            new EloquentReservationRepository,
            new EloquentRoomTypeRepository,
            $spy,
            new EloquentGuestRepository,
            app(AuditLogger::class),
        );

        $reservation = $service->create([
            'room_type_id' => $roomType->id,
            'room_id' => 999999, // does not exist — proves the spy, not the DB, resolved it
            'guest_id' => $guest->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
        ], $owner);

        $this->assertTrue($spy->wasCalled);
        $this->assertSame($realRoom->id, $reservation->room_id);
    }
}
