<?php

namespace App\Domain\Discovery\Repositories\Contracts;

use App\Domain\Discovery\Models\GuestFavoriteRoomType;
use Illuminate\Support\Collection;

interface GuestFavoriteRoomTypeRepositoryInterface
{
    /**
     * The guest's favourites whose room type and hotel are both still active
     * (guest-visible), newest first, with the room type + its gallery loaded.
     *
     * @return Collection<int, GuestFavoriteRoomType>
     */
    public function activeForGuest(int $guestId): Collection;

    /** Idempotent: returns the existing row when already saved. */
    public function add(int $guestId, int $roomTypeId): GuestFavoriteRoomType;

    /** Idempotent: removing a room type that isn't saved is a no-op. */
    public function remove(int $guestId, int $roomTypeId): void;
}
