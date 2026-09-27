<?php

namespace App\Domain\Checkout\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 9G — authorization for checkout (Phase 0 §7).
 *
 * `checkout.perform` = Group Owner, Hotel Manager, Reception. Checkout is
 * the guest-facing "one-tap checkout" path (R14-R19) with Reception as the
 * operational fallback (R7) — the same operational reasoning Phases 7 and 8
 * used to include Reception on check-in / service orders. Every checkout is
 * audited. A Guest has no staff checkout permission in this MVP.
 *
 * The $reservation is resolved by the controller through the approved
 * Reservation access path first; the hotel-scope check here is resolved from
 * the user's own stored access records via HotelAccessService — never a
 * client-supplied hotel_id.
 */
class CheckoutPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function perform(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('checkout.perform')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('checkout.perform')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    /**
     * The staff settlements ledger for a hotel — same `checkout.perform`
     * permission as the reservation-scoped view, just hotel-wide.
     */
    public function viewLedger(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('checkout.perform')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }
}
