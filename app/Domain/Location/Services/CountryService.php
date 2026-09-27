<?php

namespace App\Domain\Location\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Exceptions\LocationDeletionBlockedException;
use App\Domain\Location\Models\Country;
use App\Domain\Location\Repositories\Contracts\CountryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CountryService
{
    public function __construct(
        private readonly CountryRepositoryInterface $countries,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->countries->paginate($filters, $this->clampPerPage($perPage));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): Country
    {
        return DB::transaction(function () use ($data, $actor) {
            $data['code'] = $this->normalizeCode($data['code'] ?? '');

            $country = $this->countries->create($data);

            $this->auditLogger->record($actor, 'country.created', $country, after: $country->toArray());

            return $country;
        });
    }

    /**
     * Field-level update. Activation state is a distinct operation
     * (activate()/deactivate()) so every is_active change has one audited
     * path — mirroring RoomTypeService.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Country $country, array $data, ?User $actor): Country
    {
        return DB::transaction(function () use ($country, $data, $actor) {
            unset($data['is_active']);

            if (array_key_exists('code', $data)) {
                $data['code'] = $this->normalizeCode($data['code']);
            }

            $before = $country->toArray();

            $country = $this->countries->update($country, $data);

            $this->auditLogger->record($actor, 'country.updated', $country, before: $before, after: $country->toArray());

            return $country;
        });
    }

    public function activate(Country $country, ?User $actor): Country
    {
        return $this->setActive($country, true, $actor);
    }

    public function deactivate(Country $country, ?User $actor): Country
    {
        return $this->setActive($country, false, $actor);
    }

    /**
     * Delete only when nothing references the country (no cities, no
     * hotels). Otherwise the caller must deactivate instead.
     */
    public function delete(Country $country, ?User $actor): void
    {
        DB::transaction(function () use ($country, $actor): void {
            if ($this->countries->citiesCount($country) > 0 || $this->countries->hotelsCount($country) > 0) {
                throw LocationDeletionBlockedException::country();
            }

            $before = $country->toArray();

            $this->countries->delete($country);

            $this->auditLogger->record($actor, 'country.deleted', $country, before: $before);
        });
    }

    private function setActive(Country $country, bool $isActive, ?User $actor): Country
    {
        return DB::transaction(function () use ($country, $isActive, $actor) {
            $before = $country->toArray();

            $country = $this->countries->update($country, ['is_active' => $isActive]);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'country.activated' : 'country.deactivated',
                $country,
                before: $before,
                after: $country->toArray(),
            );

            return $country;
        });
    }

    private function normalizeCode(string $code): string
    {
        return Str::of($code)->trim()->upper()->value();
    }

    private function clampPerPage(int $perPage): int
    {
        return min(max($perPage, 1), 100);
    }
}
