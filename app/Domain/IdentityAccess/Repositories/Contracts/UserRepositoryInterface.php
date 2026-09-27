<?php

namespace App\Domain\IdentityAccess\Repositories\Contracts;

use App\Domain\IdentityAccess\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

interface UserRepositoryInterface
{
    public function paginate(?Builder $query = null, int $perPage = 15): LengthAwarePaginator;

    public function query(): Builder;

    public function find(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User;

    public function delete(User $user): bool;

    /**
     * @param  array<int>  $hotelIds
     */
    public function syncHotelAccess(User $user, array $hotelIds): void;
}
