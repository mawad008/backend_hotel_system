<?php

namespace App\Domain\Discovery\Services;

use App\Domain\Discovery\Repositories\Contracts\HotelCatalogRepositoryInterface;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Repositories\Contracts\RoomRepositoryInterface;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Slice 1 — the anonymous guest discovery surface. Read-only: it exposes only
 * active hotels + active room types, and its availability figure is a preview
 * computed with the same rules ReservationService uses at booking time (so the
 * two never disagree), but it never locks or writes anything.
 */
class HotelDiscoveryService
{
    public function __construct(
        private readonly HotelCatalogRepositoryInterface $catalog,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomRepositoryInterface $rooms,
    ) {}

    /**
     * @param  array<int, string>  $facilities
     */
    public function listHotels(
        ?string $city,
        ?string $search,
        int $perPage = 15,
        string $sort = 'recommended',
        ?float $minPrice = null,
        ?float $maxPrice = null,
        array $facilities = [],
    ): LengthAwarePaginator {
        return $this->catalog->paginateActiveHotels(
            $city,
            $search,
            min(max($perPage, 1), 50),
            $sort,
            $minPrice,
            $maxPrice,
            $facilities,
        );
    }

    /**
     * @return array<int, array{city: string, name: string, hotel_count: int}>
     */
    public function cities(): array
    {
        return $this->catalog->activeHotelCities();
    }

    public function findHotel(int $id): ?Hotel
    {
        return $this->catalog->findActiveHotel($id);
    }

    public function activeRoomTypes(int $hotelId): Collection
    {
        return $this->catalog->activeRoomTypesForHotel($hotelId);
    }

    /**
     * Per-room-type availability for [$checkIn, $checkOut). Room types whose
     * capacity cannot seat the party are still returned but with
     * roomsAvailable forced to 0 (the client greys them out) — mirroring how
     * the design shows sold-out rooms rather than hiding them.
     *
     * @return Collection<int, RoomAvailability>
     */
    public function availability(int $hotelId, string $checkIn, string $checkOut, int $adults, int $children): Collection
    {
        $nights = CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut));
        $party = $adults + $children;

        return $this->activeRoomTypes($hotelId)->map(function ($roomType) use ($checkIn, $checkOut, $nights, $party): RoomAvailability {
            $total = $this->rooms->countByRoomType($roomType->id);
            $blocking = $this->reservations->countOverlappingForRoomType($roomType->id, $checkIn, $checkOut);
            $free = max(0, $total - $blocking);

            if ($roomType->capacity < $party) {
                $free = 0;
            }

            return new RoomAvailability(
                roomType: $roomType,
                roomsTotal: $total,
                roomsAvailable: $free,
                nights: $nights,
                estimatedTotal: number_format((float) $roomType->base_price * $nights, 2, '.', ''),
            );
        });
    }
}
