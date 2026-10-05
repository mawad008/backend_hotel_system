<?php

namespace App\Domain\HotelGroup\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\HotelGroup\Repositories\Contracts\HotelGroupRepositoryInterface;
use App\Domain\IdentityAccess\Models\User;
use App\Support\LocalizedContent;
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
            $group = $this->hotelGroups->create($this->syncNames($data, null));

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

            $this->hotelGroups->update($group, $this->syncNames($data, $group));

            $this->auditLogger->record($actor, 'hotel-group.updated', $group, before: $before, after: $group->toArray());

            return $group;
        });
    }

    /**
     * Keep the legacy `name` column and `name_i18n` consistent: a sent
     * `name_i18n` derives `name` from its fallback-locale entry, and a bare
     * `name` update rewrites that same fallback-locale entry.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function syncNames(array $data, ?HotelGroup $group): array
    {
        $fallback = config('app.fallback_locale', 'en');

        if (array_key_exists('name_i18n', $data)) {
            $primary = LocalizedContent::primary($data['name_i18n']);
            if ($primary !== null && ! array_key_exists('name', $data)) {
                $data['name'] = $primary;
            }
        } elseif (array_key_exists('name', $data)) {
            $data['name_i18n'] = [...($group?->name_i18n ?? []), $fallback => $data['name']];
        }

        return $data;
    }
}
