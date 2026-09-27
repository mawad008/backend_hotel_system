<?php

namespace App\Domain\Inventory\Repositories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\Contracts\RoomTypeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentRoomTypeRepository implements RoomTypeRepositoryInterface
{
    /**
     * Every read goes through here so the Phase 2 inventory snapshot
     * (rooms_count / available_rooms_count / maintenance_rooms_count) is
     * always present — no date-range logic, just a current-state count.
     */
    public function query(): Builder
    {
        return RoomType::query()->withCount([
            'rooms',
            'rooms as available_rooms_count' => fn (Builder $query) => $query->where('status', 'available'),
            'rooms as maintenance_rooms_count' => fn (Builder $query) => $query->where('status', 'under_maintenance'),
        ]);
    }

    public function paginateAccessibleBy(User $user, Hotel $hotel, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()
            ->accessibleBy($user)
            ->where('hotel_id', $hotel->id)
            ->paginate($perPage);
    }

    public function find(int $id): ?RoomType
    {
        return $this->query()->find($id);
    }

    public function findForUpdate(int $id): ?RoomType
    {
        return RoomType::query()->lockForUpdate()->find($id);
    }

    public function create(array $data): RoomType
    {
        $roomType = RoomType::create($data);

        return $this->query()->find($roomType->id);
    }

    public function update(RoomType $roomType, array $data): RoomType
    {
        $roomType->update($data);

        return $this->query()->find($roomType->id);
    }
}
