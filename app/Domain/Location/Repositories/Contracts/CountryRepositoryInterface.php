<?php

namespace App\Domain\Location\Repositories\Contracts;

use App\Domain\Location\Models\Country;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CountryRepositoryInterface
{
    /**
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator;

    public function find(int $id): ?Country;

    public function findByCode(string $code): ?Country;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Country;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Country $country, array $data): Country;

    public function delete(Country $country): void;

    public function citiesCount(Country $country): int;

    public function hotelsCount(Country $country): int;
}
