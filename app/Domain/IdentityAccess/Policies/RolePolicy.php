<?php

namespace App\Domain\IdentityAccess\Policies;

use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;

/**
 * roles.view grants read-only list/detail (kept separate so a role can be
 * inspected without being able to change it); roles.manage is required for
 * every write (create/update/delete). Deletion of a system role is blocked
 * at the service layer (RoleService), not here — that is a data invariant,
 * not an authorization rule.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view') || $user->hasPermission('roles.manage');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.view') || $user->hasPermission('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage');
    }
}
