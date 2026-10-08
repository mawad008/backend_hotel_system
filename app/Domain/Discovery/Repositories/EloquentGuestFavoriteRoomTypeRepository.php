<?php

namespace App\Domain\Discovery\Repositories;

use App\Domain\Discovery\Models\GuestFavoriteRoomType;
use App\Domain\Discovery\Repositories\Contracts\GuestFavoriteRoomTypeRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentGuestFavoriteRoomTypeRepository implements GuestFavoriteRoomTypeRepositoryInterface
{
    public function activeForGuest(int $guestId): Collection
    {
        return GuestFavoriteRoomType::query()
            ->with('roomType.galleryMedia')
            ->where('guest_id', $guestId)
            ->whereHas('roomType', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('hotel', fn ($hotel) => $hotel->where('is_active', true)))
            ->orderByDesc('id')
            ->get();
    }

    public function add(int $guestId, int $roomTypeId): GuestFavoriteRoomType
    {
        return GuestFavoriteRoomType::query()->firstOrCreate([
            'guest_id' => $guestId,
            'room_type_id' => $roomTypeId,
        ]);
    }

    public function remove(int $guestId, int $roomTypeId): void
    {
        GuestFavoriteRoomType::query()
            ->where('guest_id', $guestId)
            ->where('room_type_id', $roomTypeId)
            ->delete();
    }
}
