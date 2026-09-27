<?php

namespace App\Domain\Location\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Exceptions\CountryCityMismatchException;
use App\Domain\Location\Exceptions\LocationDeletionBlockedException;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use App\Domain\Location\Repositories\Contracts\CityRepositoryInterface;
use App\Domain\Location\Repositories\Contracts\CountryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CityService
{
    public function __construct(
        private readonly CityRepositoryInterface $cities,
        private readonly CountryRepositoryInterface $countries,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{search?: string|null, is_active?: bool|null, country_id?: int|null}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->cities->paginate($filters, $this->clampPerPage($perPage));
    }

    /**
     * The dependent-select lookup — always scoped to $country.
     *
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     */
    public function listForCountry(Country $country, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->cities->paginateForCountry($country, $filters, $this->clampPerPage($perPage));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): City
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertCountryExists((int) $data['country_id']);

            $city = $this->cities->create($data);

            $this->auditLogger->record($actor, 'city.created', $city, after: $city->toArray());

            return $city;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(City $city, array $data, ?User $actor): City
    {
        return DB::transaction(function () use ($city, $data, $actor) {
            unset($data['is_active']);

            if (array_key_exists('country_id', $data)) {
                $this->assertCountryExists((int) $data['country_id']);
            }

            $before = $city->toArray();

            $city = $this->cities->update($city, $data);

            $this->auditLogger->record($actor, 'city.updated', $city, before: $before, after: $city->toArray());

            return $city;
        });
    }

    public function activate(City $city, ?User $actor): City
    {
        return $this->setActive($city, true, $actor);
    }

    public function deactivate(City $city, ?User $actor): City
    {
        return $this->setActive($city, false, $actor);
    }

    public function delete(City $city, ?User $actor): void
    {
        DB::transaction(function () use ($city, $actor): void {
            if ($this->cities->hotelsCount($city) > 0) {
                throw LocationDeletionBlockedException::city();
            }

            $before = $city->toArray();

            $this->cities->delete($city);

            $this->auditLogger->record($actor, 'city.deleted', $city, before: $before);
        });
    }

    /**
     * Relationship-integrity guard shared by hotel validation: the City
     * must belong to the given Country.
     */
    public function assertCityBelongsToCountry(int $cityId, int $countryId): void
    {
        $city = $this->cities->find($cityId);

        if (! $city || $city->country_id !== $countryId) {
            throw new CountryCityMismatchException;
        }
    }

    private function assertCountryExists(int $countryId): void
    {
        if (! $this->countries->find($countryId)) {
            throw new CountryCityMismatchException;
        }
    }

    private function setActive(City $city, bool $isActive, ?User $actor): City
    {
        return DB::transaction(function () use ($city, $isActive, $actor) {
            $before = $city->toArray();

            $city = $this->cities->update($city, ['is_active' => $isActive]);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'city.activated' : 'city.deactivated',
                $city,
                before: $before,
                after: $city->toArray(),
            );

            return $city;
        });
    }

    private function clampPerPage(int $perPage): int
    {
        return min(max($perPage, 1), 100);
    }
}
