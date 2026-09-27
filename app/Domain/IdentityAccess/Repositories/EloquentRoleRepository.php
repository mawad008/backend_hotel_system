<?php

namespace App\Domain\IdentityAccess\Repositories;

use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function all(): Collection
    {
        return Role::with('permissions')->withCount('users')->get();
    }

    public function find(int $id): ?Role
    {
        return Role::with('permissions')->withCount('users')->find($id);
    }

    public function findBySlug(string $slug): ?Role
    {
        return Role::where('slug', $slug)->first();
    }

    public function create(array $data): Role
    {
        $permissionIds = $data['permission_ids'] ?? [];
        unset($data['permission_ids']);

        $data['is_system'] = false;

        $role = Role::create($data);
        $role->permissions()->sync($permissionIds);

        return $role->refresh()->load('permissions')->loadCount('users');
    }

    public function update(Role $role, array $data): Role
    {
        $permissionIds = $data['permission_ids'] ?? null;
        unset($data['permission_ids'], $data['is_system'], $data['slug']);

        $role->update($data);

        if ($permissionIds !== null) {
            $role->permissions()->sync($permissionIds);
        }

        return $role->refresh()->load('permissions')->loadCount('users');
    }

    public function delete(Role $role): void
    {
        $role->delete();
    }

    public function usersCount(Role $role): int
    {
        return $role->users()->count();
    }
}
