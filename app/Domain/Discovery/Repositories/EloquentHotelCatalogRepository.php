<?php

namespace App\Domain\Discovery\Repositories;

use App\Domain\Discovery\Repositories\Contracts\HotelCatalogRepositoryInterface;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Location\Models\City;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Review\Models\Review;
use App\Support\LocalizedContent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentHotelCatalogRepository implements HotelCatalogRepositoryInterface
{
    /**
     * @param  array<int, string>  $facilities
     */
    public function paginateActiveHotels(
        ?string $city,
        ?string $search,
        int $perPage,
        string $sort = 'recommended',
        ?float $minPrice = null,
        ?float $maxPrice = null,
        array $facilities = [],
    ): LengthAwarePaginator {
        $query = Hotel::query()
            ->where('is_active', true)
            ->with(['logo', 'cover', 'facilities', 'cityRef', 'countryRef'])
            ->withMin(
                ['roomTypes as price_from' => fn (Builder $q) => $q->where('is_active', true)],
                'base_price',
            )
            ->withCount(['roomTypes as room_types_count' => fn (Builder $q) => $q->where('is_active', true)])
            ->withAvg(
                ['reviews as avg_rating' => fn (Builder $q) => $q->where('status', Review::STATUS_PUBLISHED)],
                'rating',
            )
            ->withCount(['reviews as reviews_count' => fn (Builder $q) => $q->where('status', Review::STATUS_PUBLISHED)])
            ->when($city !== null && $city !== '', fn (Builder $q) => $q->where('city', $city))
            ->when($search !== null && $search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('city', 'like', '%'.$search.'%')
                    // Arabic city names live on the normalized City row.
                    ->orWhereHas('cityRef', fn (Builder $c) => $c->where('name_ar', 'like', '%'.$search.'%'));
            }))
            ->when($minPrice !== null, fn (Builder $q) => $q->having('price_from', '>=', $minPrice))
            ->when($maxPrice !== null, fn (Builder $q) => $q->having('price_from', '<=', $maxPrice))
            ->when(count($facilities) > 0, fn (Builder $q) => $q->whereHas(
                'facilities',
                fn (Builder $inner) => $inner->whereIn('facilities.key', $facilities),
                '=',
                count($facilities),
            ));

        $query = match ($sort) {
            'highest_rated' => $query->orderByRaw('avg_rating IS NULL')->orderByDesc('avg_rating'),
            'cheapest' => $query->orderByRaw('price_from IS NULL')->orderBy('price_from'),
            default => $query->withCount([
                'reservations as bookings_count' => fn (Builder $q) => $q->where('status', '!=', Reservation::STATUS_CANCELLED),
            ])->orderByDesc('bookings_count'),
        };

        return $query->orderBy('name')->paginate($perPage);
    }

    public function findActiveHotel(int $id): ?Hotel
    {
        return Hotel::query()
            ->where('is_active', true)
            ->with(['logo', 'cover', 'galleryMedia', 'facilities', 'cityRef', 'countryRef'])
            ->withAvg(
                ['reviews as avg_rating' => fn (Builder $q) => $q->where('status', Review::STATUS_PUBLISHED)],
                'rating',
            )
            ->withCount(['reviews as reviews_count' => fn (Builder $q) => $q->where('status', Review::STATUS_PUBLISHED)])
            ->find($id);
    }

    public function activeHotelCities(): array
    {
        return Hotel::query()
            ->where('is_active', true)
            ->selectRaw('city, count(*) as hotel_count')
            ->groupBy('city')
            ->orderBy('city')
            ->get()
            ->pipe(function (Collection $rows): Collection {
                // `hotels.city` is kept in sync with City.name_en, so the
                // display name in the request locale comes from that row.
                $names = City::query()->whereIn('name_en', $rows->pluck('city'))->get()->keyBy('name_en');

                return $rows->map(fn ($row) => [
                    'city' => (string) $row->city,
                    'name' => ($c = $names->get($row->city))
                        ? LocalizedContent::resolve(['en' => $c->name_en, 'ar' => $c->name_ar], (string) $row->city)
                        : (string) $row->city,
                    'hotel_count' => (int) $row->hotel_count,
                ]);
            })
            ->all();
    }

    public function activeRoomTypesForHotel(int $hotelId): Collection
    {
        return RoomType::query()
            ->with('galleryMedia')
            ->withCount([
                'rooms',
                'rooms as available_rooms_count' => fn (Builder $query) => $query->where('status', 'available'),
                'rooms as maintenance_rooms_count' => fn (Builder $query) => $query->where('status', 'under_maintenance'),
            ])
            ->where('hotel_id', $hotelId)
            ->where('is_active', true)
            ->orderBy('base_price')
            ->get();
    }
}
