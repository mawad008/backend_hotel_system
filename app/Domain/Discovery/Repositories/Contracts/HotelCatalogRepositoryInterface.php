<?php

namespace App\Domain\Discovery\Repositories\Contracts;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read-only public catalogue queries (Slice 1 — guest discovery). Every method
 * is scoped to *active* hotels / *active* room types — the anonymous guest
 * surface never exposes a deactivated property or room type.
 */
interface HotelCatalogRepositoryInterface
{
    /**
     * @param  array<int, string>  $facilities  facility keys a hotel must have ALL of
     */
    public function paginateActiveHotels(
        ?string $city,
        ?string $search,
        int $perPage,
        string $sort = 'recommended',
        ?float $minPrice = null,
        ?float $maxPrice = null,
        array $facilities = [],
    ): LengthAwarePaginator;

    public function findActiveHotel(int $id): ?Hotel;

    /**
     * @return array<int, array{city: string, name: string, hotel_count: int}>
     */
    public function activeHotelCities(): array;

    /**
     * Active room types for an active hotel, each carrying the inventory
     * snapshot counts (rooms_count etc.), ordered by base_price.
     *
     * @return Collection<int, RoomType>
     */
    public function activeRoomTypesForHotel(int $hotelId): Collection;
}
