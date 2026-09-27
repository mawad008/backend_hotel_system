<?php

namespace App\Domain\StayServices\Policies;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 8E — authorization for reservation service orders (Phase 0 §7).
 *
 * `service-orders.view` / `service-orders.manage` = Group Owner, Hotel
 * Manager, Reception. Recording an in-stay service request is an
 * operational action (R7/R14-R19 — Reception is the operational fallback
 * for guest-facing flows), not a "financial edit/delete" of a payment or
 * invoice record (§32). Every mutation is audited. A Guest gets nothing —
 * guest authentication does not exist in this MVP (deferred, see the
 * Phase 8 report).
 *
 * The $reservation is resolved by the controller through the approved
 * Reservation access path first; the hotel-scope check here is resolved
 * from the user's own stored access records via HotelAccessService.
 */
class ServiceOrderPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function viewAny(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('service-orders.view')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $this->viewAny($user, $reservation);
    }

    public function create(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('service-orders.manage')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    public function transition(User $user, Reservation $reservation): bool
    {
        return $this->create($user, $reservation);
    }
}
