<?php

namespace App\Domain\Location\Repositories;

use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use App\Domain\Location\Repositories\Contracts\CityRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCityRepository implements CityRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with('country:id,name_en,name_ar')
            ->orderBy('name_en')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateForCountry(Country $country, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $filters['country_id'] = $country->id;

        return $this->query($filters)
            ->orderBy('name_en')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $id): ?City
    {
        return City::query()->with('country:id,name_en,name_ar')->find($id);
    }

    public function create(array $data): City
    {
        return City::create($data)->refresh()->load('country:id,name_en,name_ar');
    }

    public function update(City $city, array $data): City
    {
        $city->update($data);

        return $city->refresh()->load('country:id,name_en,name_ar');
    }

    public function delete(City $city): void
    {
        $city->delete();
    }

    public function hotelsCount(City $city): int
    {
        return $city->hotels()->count();
    }

    /**
     * @param  array{search?: string|null, is_active?: bool|null, country_id?: int|null}  $filters
     */
    private function query(array $filters): Builder
    {
        $search = $filters['search'] ?? null;
        $isActive = $filters['is_active'] ?? null;
        $countryId = $filters['country_id'] ?? null;

        return City::query()
            ->when($countryId !== null, fn (Builder $q) => $q->where('country_id', $countryId))
            ->when($isActive !== null, fn (Builder $q) => $q->where('is_active', $isActive))
            ->when($search !== null && $search !== '', function (Builder $q) use ($search): void {
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('name_en', 'like', '%'.$search.'%')
                        ->orWhere('name_ar', 'like', '%'.$search.'%');
                });
            });
    }
}
