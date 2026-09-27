<?php

namespace App\Domain\HotelGroup\Repositories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Repositories\Contracts\HotelRepositoryInterface;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentHotelRepository implements HotelRepositoryInterface
{
    /**
     * @param  array{search?: string|null, is_active?: bool|null, sort?: string|null}  $filters
     */
    public function paginateAccessibleBy(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Hotel::query()
            ->accessibleBy($user)
            ->with(['hotelGroup', 'countryRef', 'cityRef', 'logo', 'cover', 'facilities'])
            ->when(($filters['is_active'] ?? null) !== null, fn (Builder $q) => $q->where('is_active', $filters['is_active']))
            ->when(($filters['search'] ?? null) !== null && $filters['search'] !== '', function (Builder $q) use ($filters): void {
                $search = $filters['search'];
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                });
            })
            ->tap(fn (Builder $q) => $this->applySort($q, $filters['sort'] ?? null))
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $id): ?Hotel
    {
        return Hotel::query()
            ->with(['hotelGroup', 'countryRef', 'cityRef', 'logo', 'cover', 'galleryMedia', 'facilities'])
            ->find($id);
    }

    public function create(array $data): Hotel
    {
        return Hotel::create($data)->refresh();
    }

    public function update(Hotel $hotel, array $data): Hotel
    {
        $hotel->update($data);

        return $hotel->refresh();
    }

    public function delete(Hotel $hotel): void
    {
        $hotel->delete();
    }

    private function applySort(Builder $query, ?string $sort): void
    {
        $column = ltrim((string) $sort, '-');
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';

        match ($column) {
            'name' => $query->orderBy('name', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
            default => $query->orderBy('name'),
        };
    }
}
