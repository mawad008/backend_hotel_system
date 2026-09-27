<?php

namespace App\Domain\IdentityAccess\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Exceptions\RoleDeletionBlockedException;
use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function list(): Collection
    {
        return $this->roles->all();
    }

    public function find(int $id): ?Role
    {
        return $this->roles->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): Role
    {
        return DB::transaction(function () use ($data, $actor) {
            $data['slug'] = $this->uniqueSlug($data['name_en']);

            $role = $this->roles->create($data);

            $this->auditLogger->record($actor, 'role.created', $role, after: $role->toArray());

            return $role;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data, ?User $actor): Role
    {
        return DB::transaction(function () use ($role, $data, $actor) {
            $before = $role->toArray();

            $role = $this->roles->update($role, $data);

            $this->auditLogger->record($actor, 'role.updated', $role, before: $before, after: $role->toArray());

            return $role;
        });
    }

    /**
     * A system role (Group Owner / Hotel Manager / Reception / Guest) can
     * never be deleted. A custom role still assigned to one or more users
     * must be reassigned first — there is no silent cascade.
     */
    public function delete(Role $role, ?User $actor): void
    {
        DB::transaction(function () use ($role, $actor): void {
            if ($role->is_system) {
                throw RoleDeletionBlockedException::systemRole();
            }

            if ($this->roles->usersCount($role) > 0) {
                throw RoleDeletionBlockedException::assignedToUsers();
            }

            $before = $role->toArray();

            $this->roles->delete($role);

            $this->auditLogger->record($actor, 'role.deleted', $role, before: $before);
        });
    }

    /**
     * Custom roles have no slug field in the create form — the slug is a
     * stable technical identifier derived from the English name once, at
     * creation time, and never changes afterward (the repository strips
     * `slug` from update data).
     */
    private function uniqueSlug(string $nameEn): string
    {
        $base = Str::slug($nameEn, '_');
        $slug = $base;
        $suffix = 2;

        while (Role::where('slug', $slug)->exists()) {
            $slug = $base.'_'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
