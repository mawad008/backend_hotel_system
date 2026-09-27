<?php

namespace App\Domain\Location\Repositories;

use App\Domain\Location\Models\Country;
use App\Domain\Location\Repositories\Contracts\CountryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCountryRepository implements CountryRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($filters)
            ->withCount('cities')
            ->orderBy('name_en')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $id): ?Country
    {
        return Country::query()->withCount('cities')->find($id);
    }

    public function findByCode(string $code): ?Country
    {
        return Country::query()->where('code', $code)->first();
    }

    public function create(array $data): Country
    {
        return Country::create($data)->refresh()->loadCount('cities');
    }

    public function update(Country $country, array $data): Country
    {
        $country->update($data);

        return $country->refresh()->loadCount('cities');
    }

    public function delete(Country $country): void
    {
        $country->delete();
    }

    public function citiesCount(Country $country): int
    {
        return $country->cities()->count();
    }

    public function hotelsCount(Country $country): int
    {
        return $country->hotels()->count();
    }

    /**
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     */
    private function query(array $filters): Builder
    {
        $search = $filters['search'] ?? null;
        $isActive = $filters['is_active'] ?? null;

        return Country::query()
            ->when($isActive !== null, fn (Builder $q) => $q->where('is_active', $isActive))
            ->when($search !== null && $search !== '', function (Builder $q) use ($search): void {
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('name_en', 'like', '%'.$search.'%')
                        ->orWhere('name_ar', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%');
                });
            });
    }
}
