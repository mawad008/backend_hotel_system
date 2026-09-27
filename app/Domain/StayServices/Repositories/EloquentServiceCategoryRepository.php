<?php

namespace App\Domain\StayServices\Repositories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\ServiceCategory;
use App\Domain\StayServices\Repositories\Contracts\ServiceCategoryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentServiceCategoryRepository implements ServiceCategoryRepositoryInterface
{
    public function paginateForHotel(User $user, Hotel $hotel, int $perPage = 15): LengthAwarePaginator
    {
        return ServiceCategory::query()
            ->accessibleBy($user)
            ->where('hotel_id', $hotel->id)
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function activeForHotel(Hotel $hotel): Collection
    {
        return ServiceCategory::query()
            ->where('hotel_id', $hotel->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?ServiceCategory
    {
        return ServiceCategory::query()->find($id);
    }

    public function create(array $data): ServiceCategory
    {
        return ServiceCategory::create($data)->refresh();
    }

    public function update(ServiceCategory $category, array $data): ServiceCategory
    {
        $category->update($data);

        return $category->refresh();
    }
}
