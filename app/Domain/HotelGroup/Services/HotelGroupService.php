<?php

namespace App\Domain\HotelGroup\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\HotelGroup\Repositories\Contracts\HotelGroupRepositoryInterface;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class HotelGroupService
{
    public function __construct(
        private readonly HotelGroupRepositoryInterface $hotelGroups,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->hotelGroups->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): HotelGroup
    {
        return DB::transaction(function () use ($data, $actor) {
            $group = $this->hotelGroups->create($data);

            $this->auditLogger->record($actor, 'hotel-group.created', $group, after: $group->toArray());

            return $group;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(HotelGroup $group, array $data, ?User $actor): HotelGroup
    {
        return DB::transaction(function () use ($group, $data, $actor) {
            $before = $group->toArray();

            $this->hotelGroups->update($group, $data);

            $this->auditLogger->record($actor, 'hotel-group.updated', $group, before: $before, after: $group->toArray());

            return $group;
        });
    }
}
