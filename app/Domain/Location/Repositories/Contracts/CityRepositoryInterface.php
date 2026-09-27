<?php

namespace App\Domain\Location\Repositories\Contracts;

use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CityRepositoryInterface
{
    /**
     * @param  array{search?: string|null, is_active?: bool|null, country_id?: int|null}  $filters
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * Cities belonging to one country — the dependent-select lookup. Always
     * scoped to $country; never leaks another country's cities.
     *
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     */
    public function paginateForCountry(Country $country, array $filters, int $perPage = 15): LengthAwarePaginator;

    public function find(int $id): ?City;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): City;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(City $city, array $data): City;

    public function delete(City $city): void;

    public function hotelsCount(City $city): int;
}
