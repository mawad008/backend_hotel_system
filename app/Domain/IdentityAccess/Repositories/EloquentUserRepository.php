<?php

namespace App\Domain\IdentityAccess\Repositories;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentUserRepository implements UserRepositoryInterface
{
    public function query(): Builder
    {
        return User::query()->with('role');
    }

    public function paginate(?Builder $query = null, int $perPage = 15): LengthAwarePaginator
    {
        return ($query ?? $this->query())->paginate($perPage);
    }

    public function find(int $id): ?User
    {
        return $this->query()->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->query()->where('email', $email)->first();
    }

    public function create(array $data): User
    {
        return User::create($data)->refresh();
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user->refresh();
    }

    public function delete(User $user): bool
    {
        return (bool) $user->delete();
    }

    public function syncHotelAccess(User $user, array $hotelIds): void
    {
        $user->hotels()->sync($hotelIds);
    }
}
