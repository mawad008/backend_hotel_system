<?php

namespace App\Domain\StayServices\Repositories\Contracts;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\ServiceCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ServiceCategoryRepositoryInterface
{
    /**
     * Categories for $hotel, restricted to what $user may access (Group
     * Owner bypass via the HotelScoped trait).
     */
    public function paginateForHotel(User $user, Hotel $hotel, int $perPage = 15): LengthAwarePaginator;

    /**
     * Active categories for $hotel — no caller identity, used by the
     * anonymous/guest catalog read (mirrors HotelDiscoveryService's
     * active-only public reads). Unpaginated: a hotel's category list is
     * small and the guest UI renders it as a single grouped list.
     *
     * @return Collection<int, ServiceCategory>
     */
    public function activeForHotel(Hotel $hotel): Collection;

    public function find(int $id): ?ServiceCategory;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ServiceCategory;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ServiceCategory $category, array $data): ServiceCategory;
}
