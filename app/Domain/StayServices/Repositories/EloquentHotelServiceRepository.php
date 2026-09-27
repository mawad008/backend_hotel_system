<?php

namespace App\Domain\StayServices\Repositories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceReview;
use App\Domain\StayServices\Repositories\Contracts\HotelServiceRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentHotelServiceRepository implements HotelServiceRepositoryInterface
{
    public function paginateForHotel(User $user, Hotel $hotel, ?bool $onlyActive = null, int $perPage = 15): LengthAwarePaginator
    {
        return HotelService::query()
            ->accessibleBy($user)
            ->where('hotel_id', $hotel->id)
            ->when($onlyActive !== null, fn ($query) => $query->where('is_active', $onlyActive))
            ->withAvg(
                ['reviews as avg_rating' => fn (Builder $q) => $q->where('status', ServiceReview::STATUS_PUBLISHED)],
                'rating',
            )
            ->withCount(['reviews as reviews_count' => fn (Builder $q) => $q->where('status', ServiceReview::STATUS_PUBLISHED)])
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function activeForHotel(Hotel $hotel): Collection
    {
        return HotelService::query()
            ->where('hotel_id', $hotel->id)
            ->where('is_active', true)
            // Real, per-service average rating from published service
            // reviews only — never fabricated, absent entirely (`null`)
            // when a service has none yet (see ServiceResource).
            ->withAvg(
                ['reviews as avg_rating' => fn (Builder $q) => $q->where('status', ServiceReview::STATUS_PUBLISHED)],
                'rating',
            )
            ->withCount(['reviews as reviews_count' => fn (Builder $q) => $q->where('status', ServiceReview::STATUS_PUBLISHED)])
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?HotelService
    {
        return HotelService::query()->find($id);
    }

    public function findForUpdate(int $id): ?HotelService
    {
        return HotelService::query()->lockForUpdate()->find($id);
    }

    public function create(array $data): HotelService
    {
        return HotelService::create($data)->refresh();
    }

    public function update(HotelService $service, array $data): HotelService
    {
        $service->update($data);

        return $service->refresh();
    }
}
