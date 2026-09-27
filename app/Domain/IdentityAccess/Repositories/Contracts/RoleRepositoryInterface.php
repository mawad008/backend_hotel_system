<?php

namespace App\Domain\IdentityAccess\Repositories\Contracts;

use App\Domain\IdentityAccess\Models\Role;
use Illuminate\Support\Collection;

interface RoleRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): ?Role;

    public function findBySlug(string $slug): ?Role;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Role;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data): Role;

    public function delete(Role $role): void;

    public function usersCount(Role $role): int;
}
