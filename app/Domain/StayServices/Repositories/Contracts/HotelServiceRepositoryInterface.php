<?php

namespace App\Domain\StayServices\Repositories\Contracts;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\HotelService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface HotelServiceRepositoryInterface
{
    /**
     * Services for $hotel, restricted to what $user may access. $onlyActive
     * null = all; true = active only; false = inactive only.
     */
    public function paginateForHotel(User $user, Hotel $hotel, ?bool $onlyActive = null, int $perPage = 15): LengthAwarePaginator;

    /**
     * Active services for $hotel — no caller identity, used by the
     * anonymous/guest catalog read (mirrors HotelDiscoveryService's
     * active-only public reads).
     *
     * @return Collection<int, HotelService>
     */
    public function activeForHotel(Hotel $hotel): Collection;

    public function find(int $id): ?HotelService;

    /**
     * `find()` under a `SELECT ... FOR UPDATE` row lock. Call only from
     * within an active DB::transaction().
     */
    public function findForUpdate(int $id): ?HotelService;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): HotelService;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(HotelService $service, array $data): HotelService;
}
