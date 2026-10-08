<?php

namespace App\Domain\Discovery\Services;

use App\Domain\Discovery\Models\GuestFavoriteRoomType;
use App\Domain\Discovery\Repositories\Contracts\GuestFavoriteRoomTypeRepositoryInterface;
use App\Domain\Discovery\Repositories\Contracts\HotelCatalogRepositoryInterface;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * The guest's saved rooms (room types). Only guest-visible room types — active
 * and in an active hotel, the same rule as anonymous discovery — can be saved
 * or listed, so an inactive or missing room type is an identical 404.
 */
class GuestFavoriteRoomTypeService
{
    public function __construct(
        private readonly GuestFavoriteRoomTypeRepositoryInterface $favorites,
        private readonly HotelCatalogRepositoryInterface $catalog,
    ) {}

    /** @return Collection<int, GuestFavoriteRoomType> */
    public function listFor(Guest $guest): Collection
    {
        return $this->favorites->activeForGuest($guest->id);
    }

    public function add(Guest $guest, int $roomTypeId): GuestFavoriteRoomType
    {
        $favorite = $this->favorites->add($guest->id, $this->visibleRoomType($roomTypeId)->id);

        return $favorite->load('roomType.galleryMedia');
    }

    public function remove(Guest $guest, int $roomTypeId): void
    {
        $this->favorites->remove($guest->id, $roomTypeId);
    }

    private function visibleRoomType(int $roomTypeId): RoomType
    {
        $roomType = RoomType::query()
            ->where('id', $roomTypeId)
            ->where('is_active', true)
            ->first();

        if ($roomType === null || $this->catalog->findActiveHotel($roomType->hotel_id) === null) {
            throw (new ModelNotFoundException)->setModel(RoomType::class, [$roomTypeId]);
        }

        return $roomType;
    }
}
