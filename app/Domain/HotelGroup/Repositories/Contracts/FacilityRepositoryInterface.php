<?php

namespace App\Domain\HotelGroup\Repositories\Contracts;

use App\Domain\HotelGroup\Models\Facility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface FacilityRepositoryInterface
{
    /**
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * Every active facility, ordered for a create/edit picker — no
     * pagination.
     *
     * @return Collection<int, Facility>
     */
    public function allActive(): Collection;

    public function find(int $id): ?Facility;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Facility;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Facility $facility, array $data): Facility;

    public function delete(Facility $facility): void;

    public function hotelsCount(Facility $facility): int;
}
