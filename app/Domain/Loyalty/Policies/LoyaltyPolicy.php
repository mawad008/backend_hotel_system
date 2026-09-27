<?php

namespace App\Domain\Loyalty\Policies;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 10 — authorization for the reservation-scoped loyalty surface
 * (Phase 0 §7).
 *
 * `loyalty.view` = Group Owner, Hotel Manager, Reception — front-desk staff
 * routinely tell a guest their balance / history (operational, R7).
 * `loyalty.manage` (earn + redeem) = Group Owner, Hotel Manager only —
 * redeeming points has a monetary effect on a booking, and §32 gives
 * Reception no financial-edit capability (same split Phases 8/9 used for
 * pricing config / financial actions).
 *
 * The $reservation is resolved by the controller through the approved
 * Reservation access path first; hotel scope is resolved here from the
 * user's own stored access records via HotelAccessService — never a
 * client-supplied hotel_id. A Guest holds no staff permission in this MVP.
 */
class LoyaltyPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('loyalty.view')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    public function manage(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('loyalty.manage')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    /**
     * The guest-level loyalty dashboard — a Guest is not hotel-bound (they
     * may book, and so earn, across multiple hotels in the group), so
     * unlike the reservation-scoped view() there is no HotelAccessService
     * check here — `loyalty.view` alone gates it, mirroring GuestPolicy's
     * shape for the other hotel-independent staff resource.
     */
    public function viewForGuest(User $user, Guest $guest): bool
    {
        return $user->hasPermission('loyalty.view');
    }
}
