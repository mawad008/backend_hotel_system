<?php

namespace App\Domain\StayServices\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceCategory;
use App\Domain\StayServices\Repositories\Contracts\HotelServiceRepositoryInterface;
use App\Domain\StayServices\Repositories\Contracts\ServiceCategoryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8C — the service-catalog business surface: hotel-scoped service
 * categories and services, their creation, field updates, and
 * activation/deactivation.
 *
 * Deletion is intentionally not offered: a category or service referenced
 * by historical service orders / folio charges must stay resolvable, so the
 * catalog-exit path is always deactivation (Phase 8C instructions:
 * "Prefer deactivation where historical data requires preservation").
 *
 * `hotel_id` is always taken from the already-authorized route Hotel, never
 * from client-supplied data — mirroring RoomTypeService.
 */
class ServiceCatalogService
{
    public function __construct(
        private readonly ServiceCategoryRepositoryInterface $categories,
        private readonly HotelServiceRepositoryInterface $services,
        private readonly AuditLogger $auditLogger,
    ) {}

    // ── Categories ─────────────────────────────────────────────────────

    public function listCategories(User $user, Hotel $hotel, int $perPage = 15): LengthAwarePaginator
    {
        return $this->categories->paginateForHotel($user, $hotel, $perPage);
    }

    public function findCategory(int $id): ?ServiceCategory
    {
        return $this->categories->find($id);
    }

    /**
     * Active categories for the anonymous/guest catalog read.
     *
     * @return Collection<int, ServiceCategory>
     */
    public function activeCategoriesForHotel(Hotel $hotel): Collection
    {
        return $this->categories->activeForHotel($hotel);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(Hotel $hotel, array $data, ?User $actor): ServiceCategory
    {
        return DB::transaction(function () use ($hotel, $data, $actor) {
            $data['hotel_id'] = $hotel->id;
            unset($data['is_active']);

            $category = $this->categories->create($data);

            $this->auditLogger->record(
                $actor,
                'service_category.created',
                $category,
                after: $category->toArray(),
                hotelId: $hotel->id,
            );

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(ServiceCategory $category, array $data, ?User $actor): ServiceCategory
    {
        return DB::transaction(function () use ($category, $data, $actor) {
            unset($data['hotel_id'], $data['is_active']);

            $before = $category->toArray();
            $category = $this->categories->update($category, $data);

            $this->auditLogger->record(
                $actor,
                'service_category.updated',
                $category,
                before: $before,
                after: $category->toArray(),
                hotelId: $category->hotel_id,
            );

            return $category;
        });
    }

    public function activateCategory(ServiceCategory $category, ?User $actor): ServiceCategory
    {
        return $this->setCategoryActive($category, true, $actor);
    }

    public function deactivateCategory(ServiceCategory $category, ?User $actor): ServiceCategory
    {
        return $this->setCategoryActive($category, false, $actor);
    }

    private function setCategoryActive(ServiceCategory $category, bool $isActive, ?User $actor): ServiceCategory
    {
        return DB::transaction(function () use ($category, $isActive, $actor) {
            $before = $category->toArray();
            $category = $this->categories->update($category, ['is_active' => $isActive]);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'service_category.activated' : 'service_category.deactivated',
                $category,
                before: $before,
                after: $category->toArray(),
                hotelId: $category->hotel_id,
            );

            return $category;
        });
    }

    // ── Services ───────────────────────────────────────────────────────

    public function listServices(User $user, Hotel $hotel, ?bool $onlyActive = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->services->paginateForHotel($user, $hotel, $onlyActive, $perPage);
    }

    public function findService(int $id): ?HotelService
    {
        return $this->services->find($id);
    }

    /**
     * Active services for the anonymous/guest catalog read.
     *
     * @return Collection<int, HotelService>
     */
    public function activeServicesForHotel(Hotel $hotel): Collection
    {
        return $this->services->activeForHotel($hotel);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createService(Hotel $hotel, array $data, ?User $actor): HotelService
    {
        return DB::transaction(function () use ($hotel, $data, $actor) {
            $data['hotel_id'] = $hotel->id;
            unset($data['is_active']);

            // A category, if given, must belong to the same hotel — a
            // cross-hotel category id can never be attached.
            $data['service_category_id'] = $this->resolveCategoryId(
                $hotel,
                $data['service_category_id'] ?? null,
            );

            $service = $this->services->create($data);

            $this->auditLogger->record(
                $actor,
                'service.created',
                $service,
                after: $service->toArray(),
                hotelId: $hotel->id,
            );

            return $service;
        });
    }

    /**
     * Field-level update. `is_active` is a distinct operation
     * (activate()/deactivate()) and is never accepted here.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateService(HotelService $service, array $data, ?User $actor): HotelService
    {
        return DB::transaction(function () use ($service, $data, $actor) {
            unset($data['hotel_id'], $data['is_active']);

            if (array_key_exists('service_category_id', $data)) {
                $data['service_category_id'] = $this->resolveCategoryId(
                    $service->hotel,
                    $data['service_category_id'],
                );
            }

            $before = $service->toArray();
            $service = $this->services->update($service, $data);

            $this->auditLogger->record(
                $actor,
                'service.updated',
                $service,
                before: $before,
                after: $service->toArray(),
                hotelId: $service->hotel_id,
            );

            return $service;
        });
    }

    public function activateService(HotelService $service, ?User $actor): HotelService
    {
        return $this->setServiceActive($service, true, $actor);
    }

    public function deactivateService(HotelService $service, ?User $actor): HotelService
    {
        return $this->setServiceActive($service, false, $actor);
    }

    private function setServiceActive(HotelService $service, bool $isActive, ?User $actor): HotelService
    {
        return DB::transaction(function () use ($service, $isActive, $actor) {
            $before = $service->toArray();
            $service = $this->services->update($service, ['is_active' => $isActive]);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'service.activated' : 'service.deactivated',
                $service,
                before: $before,
                after: $service->toArray(),
                hotelId: $service->hotel_id,
            );

            return $service;
        });
    }

    /**
     * Null passes straight through (a service may have no category). A
     * non-null id is only accepted when it resolves to a category of the
     * same hotel — otherwise it is treated as absent (null), never trusted.
     */
    private function resolveCategoryId(Hotel $hotel, mixed $categoryId): ?int
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $category = $this->categories->find((int) $categoryId);

        if ($category === null || $category->hotel_id !== $hotel->id) {
            return null;
        }

        return $category->id;
    }
}
