<?php

namespace App\Domain\HotelGroup\Policies;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;

class HotelGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hotel-groups.manage');
    }

    public function view(User $user, HotelGroup $hotelGroup): bool
    {
        return $user->hasPermission('hotel-groups.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hotel-groups.manage');
    }

    public function update(User $user, HotelGroup $hotelGroup): bool
    {
        return $user->hasPermission('hotel-groups.manage');
    }
}
