<?php

namespace App\Domain\HotelGroup\Repositories\Contracts;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface HotelRepositoryInterface
{
    /**
     * @param  array{search?: string|null, is_active?: bool|null, sort?: string|null}  $filters
     */
    public function paginateAccessibleBy(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function find(int $id): ?Hotel;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Hotel;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Hotel $hotel, array $data): Hotel;

    public function delete(Hotel $hotel): void;
}
