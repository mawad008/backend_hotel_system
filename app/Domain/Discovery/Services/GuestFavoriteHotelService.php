<?php

namespace App\Domain\Discovery\Services;

use App\Domain\Discovery\Models\GuestFavoriteHotel;
use App\Domain\Discovery\Repositories\Contracts\GuestFavoriteHotelRepositoryInterface;
use App\Domain\Discovery\Repositories\Contracts\HotelCatalogRepositoryInterface;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\Guest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * The guest's saved hotels. Only guest-visible (active) hotels can be saved
 * or listed — the same visibility rule as anonymous discovery, so an
 * inactive or missing hotel is an identical 404.
 */
class GuestFavoriteHotelService
{
    public function __construct(
        private readonly GuestFavoriteHotelRepositoryInterface $favorites,
        private readonly HotelCatalogRepositoryInterface $catalog,
    ) {}

    /** @return Collection<int, GuestFavoriteHotel> */
    public function listFor(Guest $guest): Collection
    {
        return $this->favorites->activeForGuest($guest->id);
    }

    public function add(Guest $guest, int $hotelId): GuestFavoriteHotel
    {
        return $this->favorites->add($guest->id, $this->visibleHotel($hotelId)->id);
    }

    public function remove(Guest $guest, int $hotelId): void
    {
        $this->favorites->remove($guest->id, $hotelId);
    }

    private function visibleHotel(int $hotelId): Hotel
    {
        $hotel = $this->catalog->findActiveHotel($hotelId);

        if ($hotel === null) {
            throw (new ModelNotFoundException)->setModel(Hotel::class, [$hotelId]);
        }

        return $hotel;
    }
}
