<?php

namespace App\Domain\HotelGroup\Repositories;

use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Repositories\Contracts\FacilityRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentFacilityRepository implements FacilityRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($filters)
            ->withCount('hotels')
            ->tap(fn (Builder $q) => $this->applySort($q, $filters['sort'] ?? null))
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function allActive(): Collection
    {
        return Facility::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function find(int $id): ?Facility
    {
        return Facility::query()->withCount('hotels')->find($id);
    }

    public function create(array $data): Facility
    {
        return Facility::create($data)->refresh()->loadCount('hotels');
    }

    public function update(Facility $facility, array $data): Facility
    {
        $facility->update($data);

        return $facility->refresh()->loadCount('hotels');
    }

    public function delete(Facility $facility): void
    {
        $facility->delete();
    }

    public function hotelsCount(Facility $facility): int
    {
        return $facility->hotels()->count();
    }

    /**
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     */
    private function query(array $filters): Builder
    {
        $search = $filters['search'] ?? null;
        $isActive = $filters['is_active'] ?? null;

        return Facility::query()
            ->when($isActive !== null, fn (Builder $q) => $q->where('is_active', $isActive))
            ->when($search !== null && $search !== '', function (Builder $q) use ($search): void {
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('key', 'like', '%'.$search.'%')
                        ->orWhere('name_i18n', 'like', '%'.$search.'%');
                });
            });
    }

    /**
     * `name` sorts by the request locale's translation (falling back to the
     * fallback locale), case-insensitively; no sort keeps display order.
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        $column = ltrim((string) $sort, '-');
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';

        match ($column) {
            'name' => $query->orderByRaw(
                'LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(name_i18n, ?)), JSON_UNQUOTE(JSON_EXTRACT(name_i18n, ?)))) '.$direction,
                ['$."'.app()->getLocale().'"', '$."'.config('app.fallback_locale', 'en').'"'],
            ),
            'key' => $query->orderBy('key', $direction),
            'sort_order' => $query->orderBy('sort_order', $direction),
            default => $query->orderBy('sort_order'),
        };
    }
}
