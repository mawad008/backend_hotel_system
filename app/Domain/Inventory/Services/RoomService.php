<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Exceptions\InvalidRoomStatusTransitionException;
use App\Domain\Inventory\Exceptions\RoomTypeHotelMismatchException;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\Contracts\RoomRepositoryInterface;
use App\Domain\Inventory\Repositories\Contracts\RoomTypeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RoomService
{
    /**
     * Phase 2B's only allowed operational transitions. `booked` is
     * reserved for the future Reservations domain and is deliberately
     * absent from both sides of this map — no transition into or out of
     * it exists here, regardless of what the database enum permits.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'available' => ['under_maintenance'],
        'under_maintenance' => ['available'],
    ];

    public function __construct(
        private readonly RoomRepositoryInterface $rooms,
        private readonly RoomTypeRepositoryInterface $roomTypes,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Rooms for $hotel, resolved from $user's own hotel access — never
     * from a request parameter. $roomTypeId optionally narrows the list.
     */
    public function listForHotel(User $user, Hotel $hotel, ?int $roomTypeId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->rooms->paginateAccessibleBy($user, $hotel, $roomTypeId, $perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RoomTypeHotelMismatchException if room_type_id does not
     *                                        belong to $hotel — rejected before anything is persisted.
     */
    public function create(Hotel $hotel, array $data, ?User $actor): Room
    {
        return DB::transaction(function () use ($hotel, $data, $actor) {
            $roomType = $this->roomTypes->find((int) ($data['room_type_id'] ?? 0));

            $this->assertRoomTypeBelongsToHotel($roomType, $hotel->id);

            // hotel_id always comes from the already-authorized route
            // Hotel, never from client-supplied data. A Room always
            // starts available — status changes go exclusively through
            // transitionStatus(), never through create/update.
            $data['hotel_id'] = $hotel->id;
            $data['status'] = 'available';

            $room = $this->rooms->create($data);

            $this->auditLogger->record(
                $actor,
                'room.created',
                $room,
                after: $room->toArray(),
                hotelId: $hotel->id,
            );

            return $room;
        });
    }

    /**
     * Field-level update (room_type_id, room_number). Status changes are
     * intentionally not accepted here — see transitionStatus().
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RoomTypeHotelMismatchException if a new room_type_id does
     *                                        not belong to the Room's own (immutable) hotel.
     */
    public function update(Room $room, array $data, ?User $actor): Room
    {
        return DB::transaction(function () use ($room, $data, $actor) {
            unset($data['hotel_id'], $data['status']);

            if (array_key_exists('room_type_id', $data) && (int) $data['room_type_id'] !== $room->room_type_id) {
                $newRoomType = $this->roomTypes->find((int) $data['room_type_id']);

                $this->assertRoomTypeBelongsToHotel($newRoomType, $room->hotel_id);
            }

            $before = $room->toArray();

            $this->rooms->update($room, $data);

            $this->auditLogger->record(
                $actor,
                'room.updated',
                $room,
                before: $before,
                after: $room->toArray(),
                hotelId: $room->hotel_id,
            );

            return $room;
        });
    }

    /**
     * Transitions a Room's status, re-reading and locking the row inside
     * the transaction so the validated current state can never be stale
     * — the same transaction+lock+revalidate shape §6.3 requires for the
     * future Reservations domain, applied here to the one thing Phase 2B
     * actually has to protect: two staff toggling the same Room at once.
     *
     * @throws InvalidRoomStatusTransitionException for any transition
     *                                              other than available <-> under_maintenance — `booked` can
     *                                              never be a source or target through this method.
     */
    public function transitionStatus(Room $room, string $targetStatus, ?User $actor): Room
    {
        return DB::transaction(function () use ($room, $targetStatus, $actor) {
            $current = $this->rooms->findForUpdate($room->id);

            $this->assertValidTransition($current->status, $targetStatus);

            $before = $current->toArray();

            $this->rooms->update($current, ['status' => $targetStatus]);

            $this->auditLogger->record(
                $actor,
                'room.status_updated',
                $current,
                before: $before,
                after: $current->toArray(),
                hotelId: $current->hotel_id,
            );

            return $current;
        });
    }

    private function assertRoomTypeBelongsToHotel(?RoomType $roomType, int $hotelId): void
    {
        if (! $roomType || $roomType->hotel_id !== $hotelId) {
            throw new RoomTypeHotelMismatchException;
        }
    }

    private function assertValidTransition(string $current, string $target): void
    {
        if (! in_array($target, self::ALLOWED_TRANSITIONS[$current] ?? [], true)) {
            throw new InvalidRoomStatusTransitionException($current, $target);
        }
    }
}
