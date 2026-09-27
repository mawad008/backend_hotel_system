<?php

namespace App\Domain\HotelGroup\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Exceptions\FacilityDeletionBlockedException;
use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Repositories\Contracts\FacilityRepositoryInterface;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FacilityService
{
    public function __construct(
        private readonly FacilityRepositoryInterface $facilities,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->facilities->paginate($filters, $this->clampPerPage($perPage));
    }

    /**
     * Active facilities for a Hotel create/edit picker — no pagination.
     *
     * @return Collection<int, Facility>
     */
    public function pickerOptions(): Collection
    {
        return $this->facilities->allActive();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): Facility
    {
        return DB::transaction(function () use ($data, $actor) {
            if (empty($data['key'])) {
                $data['key'] = $this->deriveKey($data['name_i18n'] ?? []);
            }

            $facility = $this->facilities->create($data);

            $this->auditLogger->record($actor, 'facility.created', $facility, after: $facility->toArray());

            return $facility;
        });
    }

    /**
     * Field-level update. Activation state is a distinct operation
     * (activate()/deactivate()) so every is_active change has one audited
     * path — mirroring CountryService.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Facility $facility, array $data, ?User $actor): Facility
    {
        return DB::transaction(function () use ($facility, $data, $actor) {
            unset($data['is_active']);

            $before = $facility->toArray();

            $facility = $this->facilities->update($facility, $data);

            $this->auditLogger->record($actor, 'facility.updated', $facility, before: $before, after: $facility->toArray());

            return $facility;
        });
    }

    public function activate(Facility $facility, ?User $actor): Facility
    {
        return $this->setActive($facility, true, $actor);
    }

    public function deactivate(Facility $facility, ?User $actor): Facility
    {
        return $this->setActive($facility, false, $actor);
    }

    /**
     * Delete only when no hotel still references the facility. Otherwise
     * the caller must deactivate instead.
     */
    public function delete(Facility $facility, ?User $actor): void
    {
        DB::transaction(function () use ($facility, $actor): void {
            if ($this->facilities->hotelsCount($facility) > 0) {
                throw FacilityDeletionBlockedException::inUse();
            }

            $before = $facility->toArray();

            $this->facilities->delete($facility);

            $this->auditLogger->record($actor, 'facility.deleted', $facility, before: $before);
        });
    }

    private function setActive(Facility $facility, bool $isActive, ?User $actor): Facility
    {
        return DB::transaction(function () use ($facility, $isActive, $actor) {
            $before = $facility->toArray();

            $facility = $this->facilities->update($facility, ['is_active' => $isActive]);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'facility.activated' : 'facility.deactivated',
                $facility,
                before: $before,
                after: $facility->toArray(),
            );

            return $facility;
        });
    }

    /**
     * @param  array<string, string|null>  $nameI18n
     */
    private function deriveKey(array $nameI18n): string
    {
        $base = $nameI18n[config('app.fallback_locale', 'en')] ?? (reset($nameI18n) ?: 'facility');

        return Str::slug((string) $base, '_');
    }

    private function clampPerPage(int $perPage): int
    {
        return min(max($perPage, 1), 100);
    }
}
