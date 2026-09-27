<?php

namespace App\Domain\Payment\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 5D — authorization for the payment HTTP surface.
 *
 * Initiating a deposit hold is a financial management action (Phase 0 §7:
 * Reception and Guest hold no "financial edit" capability), so it takes the
 * `payments.manage` permission plus the same hotel-scope check every other
 * Reservation-scoped action uses — resolved through HotelAccessService from
 * the user's own stored access records, never a client-supplied hotel_id.
 *
 * Guest authentication does not exist in this MVP, so a Guest-owns-their-
 * own-payment rule cannot be expressed; Guest-role users simply lack the
 * permission and are denied like any other unpermissioned role.
 */
class PaymentPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    /**
     * $reservation is resolved by the controller through the approved
     * Reservation access path before this call; it only selects which row
     * the hold is for and is never itself treated as proof of authorization.
     */
    public function create(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('payments.manage')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    /**
     * The staff payments ledger for a hotel — a read, not a financial
     * action, so it takes the separate `payments.view` permission (granted
     * to Reception too, unlike `payments.manage`).
     */
    public function viewLedger(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('payments.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }
}
