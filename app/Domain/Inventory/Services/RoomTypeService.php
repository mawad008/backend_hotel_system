<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\Contracts\RoomTypeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RoomTypeService
{
    public function __construct(
        private readonly RoomTypeRepositoryInterface $roomTypes,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Room Types for $hotel, resolved from $user's own hotel access —
     * never from a request parameter.
     */
    public function listForHotel(User $user, Hotel $hotel, int $perPage = 15): LengthAwarePaginator
    {
        return $this->roomTypes->paginateAccessibleBy($user, $hotel, $perPage);
    }

    /**
     * Re-fetches a Room Type with its inventory snapshot counts loaded.
     * Route model binding resolves the plain model without those counts,
     * so callers displaying a single Room Type (e.g. a show endpoint)
     * should fetch through here rather than serializing the route-bound
     * instance directly.
     */
    public function find(int $id): ?RoomType
    {
        return $this->roomTypes->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Hotel $hotel, array $data, ?User $actor): RoomType
    {
        return DB::transaction(function () use ($hotel, $data, $actor) {
            // hotel_id always comes from the already-authorized route
            // Hotel, never from client-supplied data.
            $data['hotel_id'] = $hotel->id;

            $roomType = $this->roomTypes->create($data);

            $this->auditLogger->record(
                $actor,
                'room-type.created',
                $roomType,
                after: $roomType->toArray(),
                hotelId: $hotel->id,
            );

            return $roomType;
        });
    }

    /**
     * Field-level update. Activation state is a distinct business
     * operation (see activate()/deactivate()) and is intentionally not
     * accepted here, so every is_active change has one unambiguous,
     * clearly audited path.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(RoomType $roomType, array $data, ?User $actor): RoomType
    {
        return DB::transaction(function () use ($roomType, $data, $actor) {
            unset($data['hotel_id'], $data['is_active']);

            $before = $roomType->toArray();

            $roomType = $this->roomTypes->update($roomType, $data);

            $this->auditLogger->record(
                $actor,
                'room-type.updated',
                $roomType,
                before: $before,
                after: $roomType->toArray(),
                hotelId: $roomType->hotel_id,
            );

            return $roomType;
        });
    }

    /**
     * Deactivating a Room Type only flips its own is_active flag. It
     * must never delete or modify any Room, and never cascades any other
     * business behavior — Discovery/Reservation phases decide separately
     * whether an inactive Room Type is bookable.
     */
    public function activate(RoomType $roomType, ?User $actor): RoomType
    {
        return $this->setActive($roomType, true, $actor);
    }

    public function deactivate(RoomType $roomType, ?User $actor): RoomType
    {
        return $this->setActive($roomType, false, $actor);
    }

    private function setActive(RoomType $roomType, bool $isActive, ?User $actor): RoomType
    {
        return DB::transaction(function () use ($roomType, $isActive, $actor) {
            $before = $roomType->toArray();

            $roomType = $this->roomTypes->update($roomType, ['is_active' => $isActive]);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'room-type.activated' : 'room-type.deactivated',
                $roomType,
                before: $before,
                after: $roomType->toArray(),
                hotelId: $roomType->hotel_id,
            );

            return $roomType;
        });
    }
}
