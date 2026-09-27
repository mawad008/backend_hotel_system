<?php

namespace App\Domain\Inventory\Repositories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Repositories\Contracts\RoomRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentRoomRepository implements RoomRepositoryInterface
{
    public function paginateAccessibleBy(User $user, Hotel $hotel, ?int $roomTypeId = null, int $perPage = 15): LengthAwarePaginator
    {
        return Room::query()
            ->accessibleBy($user)
            ->where('hotel_id', $hotel->id)
            ->when($roomTypeId !== null, fn ($query) => $query->where('room_type_id', $roomTypeId))
            ->paginate($perPage);
    }

    public function find(int $id): ?Room
    {
        return Room::find($id);
    }

    public function findForUpdate(int $id): ?Room
    {
        return Room::query()->lockForUpdate()->find($id);
    }

    public function create(array $data): Room
    {
        return Room::create($data)->refresh();
    }

    public function update(Room $room, array $data): Room
    {
        $room->update($data);

        return $room->refresh();
    }

    public function allForRoomType(int $roomTypeId): Collection
    {
        return Room::query()->where('room_type_id', $roomTypeId)->orderBy('room_number')->get();
    }

    public function countByRoomType(int $roomTypeId): int
    {
        return Room::query()->where('room_type_id', $roomTypeId)->count();
    }

    public function countByHotel(int $hotelId): int
    {
        return Room::query()->where('hotel_id', $hotelId)->count();
    }
}
