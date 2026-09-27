<?php

namespace App\Domain\Location\Policies;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\Country;

/**
 * Country / City are global reference data — permission-driven, never
 * hotel-scoped and never role-name checked. `locations.view` grants
 * read-only list/detail; `locations.manage` is required for every write
 * (create/update/delete/activate/deactivate).
 */
class CountryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('locations.view');
    }

    public function view(User $user, Country $country): bool
    {
        return $user->hasPermission('locations.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('locations.manage');
    }

    public function update(User $user, Country $country): bool
    {
        return $user->hasPermission('locations.manage');
    }

    public function delete(User $user, Country $country): bool
    {
        return $user->hasPermission('locations.manage');
    }
}
