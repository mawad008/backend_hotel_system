<?php

namespace App\Domain\Location\Policies;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\City;

class CityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('locations.view');
    }

    public function view(User $user, City $city): bool
    {
        return $user->hasPermission('locations.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('locations.manage');
    }

    public function update(User $user, City $city): bool
    {
        return $user->hasPermission('locations.manage');
    }

    public function delete(User $user, City $city): bool
    {
        return $user->hasPermission('locations.manage');
    }
}
