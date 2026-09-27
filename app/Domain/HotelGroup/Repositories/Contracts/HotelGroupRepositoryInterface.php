<?php

namespace App\Domain\HotelGroup\Repositories\Contracts;

use App\Domain\HotelGroup\Models\HotelGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface HotelGroupRepositoryInterface
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function find(int $id): ?HotelGroup;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): HotelGroup;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(HotelGroup $group, array $data): HotelGroup;
}
