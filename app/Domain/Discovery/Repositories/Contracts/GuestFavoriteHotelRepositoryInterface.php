<?php

namespace App\Domain\Discovery\Repositories\Contracts;

use App\Domain\Discovery\Models\GuestFavoriteHotel;
use Illuminate\Support\Collection;

interface GuestFavoriteHotelRepositoryInterface
{
    /**
     * The guest's favourites whose hotel is still active (guest-visible),
     * newest first.
     *
     * @return Collection<int, GuestFavoriteHotel>
     */
    public function activeForGuest(int $guestId): Collection;

    /** Idempotent: returns the existing row when already saved. */
    public function add(int $guestId, int $hotelId): GuestFavoriteHotel;

    /** Idempotent: removing a hotel that isn't saved is a no-op. */
    public function remove(int $guestId, int $hotelId): void;
}
