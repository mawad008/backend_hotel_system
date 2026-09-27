<?php

namespace App\Domain\HotelGroup\Policies;

use App\Domain\HotelGroup\Models\Facility;
use App\Domain\IdentityAccess\Models\User;

/**
 * The facility catalog is global reference data — permission-driven, never
 * hotel-scoped. `facilities.view` grants read-only list/detail;
 * `facilities.manage` is required for every write (create/update/delete/
 * activate/deactivate). Assigning existing facilities to a specific hotel
 * is a Hotel edit (HotelPolicy::update), not a Facility write.
 */
class FacilityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('facilities.view');
    }

    public function view(User $user, Facility $facility): bool
    {
        return $user->hasPermission('facilities.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('facilities.manage');
    }

    public function update(User $user, Facility $facility): bool
    {
        return $user->hasPermission('facilities.manage');
    }

    public function delete(User $user, Facility $facility): bool
    {
        return $user->hasPermission('facilities.manage');
    }
}
