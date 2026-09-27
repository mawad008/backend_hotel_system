<?php

namespace App\Domain\Inventory\Repositories\Contracts;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface RoomRepositoryInterface
{
    /**
     * Rooms belonging to $hotel, filtered through $user's own hotel
     * access, with an optional room_type_id filter.
     */
    public function paginateAccessibleBy(User $user, Hotel $hotel, ?int $roomTypeId = null, int $perPage = 15): LengthAwarePaginator;

    /**
     * Plain lookup by id — no authorization decision is made here, that
     * is the Policy's responsibility.
     */
    public function find(int $id): ?Room;

    /**
     * Locks the row for the duration of the caller's transaction. Must
     * only be called from within an active DB::transaction() — this is
     * the groundwork the future Reservations domain's concurrency model
     * will build on (see approved plan §6/§9); it is not itself a
     * date-range or reservation lock.
     */
    public function findForUpdate(int $id): ?Room;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Room;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Room $room, array $data): Room;

    /**
     * Total physical Rooms belonging to $roomTypeId — the capacity figure
     * the Reservation domain's Phase 3D availability check (approved
     * Option A) compares against, regardless of each Room's operational
     * status.
     */
    public function countByRoomType(int $roomTypeId): int;

    /**
     * Total physical Rooms belonging to $hotelId, regardless of room type
     * or operational status — the capacity figure the occupancy report
     * compares booked room-nights against.
     */
    public function countByHotel(int $hotelId): int;

    /**
     * Every physical Room of $roomTypeId, ordered by room number — the
     * candidate list for a front-desk room assignment.
     *
     * @return Collection<int, Room>
     */
    public function allForRoomType(int $roomTypeId): Collection;
}
