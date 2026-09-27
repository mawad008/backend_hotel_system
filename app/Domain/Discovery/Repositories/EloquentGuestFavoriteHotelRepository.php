<?php

namespace App\Domain\Discovery\Repositories;

use App\Domain\Discovery\Models\GuestFavoriteHotel;
use App\Domain\Discovery\Repositories\Contracts\GuestFavoriteHotelRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentGuestFavoriteHotelRepository implements GuestFavoriteHotelRepositoryInterface
{
    public function activeForGuest(int $guestId): Collection
    {
        return GuestFavoriteHotel::query()
            ->where('guest_id', $guestId)
            ->whereHas('hotel', fn ($query) => $query->where('is_active', true))
            ->orderByDesc('id')
            ->get();
    }

    public function add(int $guestId, int $hotelId): GuestFavoriteHotel
    {
        return GuestFavoriteHotel::query()->firstOrCreate([
            'guest_id' => $guestId,
            'hotel_id' => $hotelId,
        ]);
    }

    public function remove(int $guestId, int $hotelId): void
    {
        GuestFavoriteHotel::query()
            ->where('guest_id', $guestId)
            ->where('hotel_id', $hotelId)
            ->delete();
    }
}
