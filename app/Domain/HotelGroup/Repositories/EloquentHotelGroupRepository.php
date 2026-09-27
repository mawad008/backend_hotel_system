<?php

namespace App\Domain\HotelGroup\Repositories;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\HotelGroup\Repositories\Contracts\HotelGroupRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentHotelGroupRepository implements HotelGroupRepositoryInterface
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return HotelGroup::query()->paginate($perPage);
    }

    public function find(int $id): ?HotelGroup
    {
        return HotelGroup::find($id);
    }

    public function create(array $data): HotelGroup
    {
        return HotelGroup::create($data)->refresh();
    }

    public function update(HotelGroup $group, array $data): HotelGroup
    {
        $group->update($data);

        return $group->refresh();
    }
}
