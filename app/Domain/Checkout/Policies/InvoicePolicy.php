<?php

namespace App\Domain\Checkout\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 9G — authorization for reading a reservation's invoice (Phase 0 §7).
 *
 * `invoice.view` = Group Owner, Hotel Manager, Reception. Reading the final
 * invoice is an operational read; §32's Reception restriction is on
 * financial *edit/delete*, not read (same reasoning as Phase 8 `folio.view`).
 * A Guest has no staff permission in this MVP.
 */
class InvoicePolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('invoice.view')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    /**
     * The staff invoices ledger for a hotel — same `invoice.view`
     * permission as the reservation-scoped read, just hotel-wide.
     */
    public function viewLedger(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('invoice.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }
}
