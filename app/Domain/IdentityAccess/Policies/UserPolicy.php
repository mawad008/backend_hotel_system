<?php

namespace App\Domain\IdentityAccess\Policies;

use App\Domain\IdentityAccess\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.view') || $user->hasPermission('users.manage');
    }

    public function view(User $user, User $target): bool
    {
        return $user->is($target)
            || $user->hasPermission('users.view')
            || $user->hasPermission('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function delete(User $user, User $target): bool
    {
        return $user->hasPermission('users.manage') && ! $user->is($target);
    }
}
