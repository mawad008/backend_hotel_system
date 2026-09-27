<?php

namespace App\Domain\StayServices\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 8E — authorization for reading a reservation's folio (Phase 0 §7).
 *
 * `folio.view` = Group Owner, Hotel Manager, Reception. The folio is a
 * read-only financial summary; viewing it (to answer a guest's "what do I
 * owe?") is operational, and §32's Reception restriction is on financial
 * *edit/delete*, not read. A Guest gets nothing (no guest auth in the MVP).
 */
class FolioPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('folio.view')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    /**
     * The standalone folio ledger for a hotel — same `folio.view`
     * permission as the reservation-scoped read, just hotel-wide.
     */
    public function viewForHotel(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('folio.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }
}
