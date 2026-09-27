<?php

namespace App\Domain\Reservation\Policies;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;

/**
 * Staff authorization for the guest directory. A Guest is not hotel-bound
 * (they may book across hotels), so unlike Reservation/Payment/etc. there
 * is no HotelAccessService check here — `guests.view` alone gates it,
 * mirroring UserPolicy's shape for the other hotel-independent staff
 * resource.
 */
class GuestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('guests.view');
    }

    public function view(User $user, Guest $guest): bool
    {
        return $user->hasPermission('guests.view');
    }

    /**
     * Registering a walk-in guest (no hotel scope — same reasoning as
     * view/viewAny). `guests.manage` is a distinct permission from
     * `guests.view` so a read-only directory role never gains write access.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('guests.manage');
    }
}
