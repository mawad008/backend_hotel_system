<?php

namespace App\Domain\Reservation\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Reservation;

/**
 * Guest authentication does not exist yet, so a Guest-owns-their-own-
 * reservations rule cannot be expressed here — `Role::GUEST` staff-side
 * users simply have no `reservations.view`/`reservations.manage`
 * permission and are denied like any other unpermissioned role.
 */
class ReservationPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    /**
     * Not nested under one Hotel — a Reservation route is not designed
     * that way (Phase 3C) — so this only gates the permission itself.
     * Which hotels' reservations are actually returned is resolved by the
     * repository from the user's own access records, not from here.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('reservations.view');
    }

    /**
     * The hotel scope check is resolved from the authenticated user's own
     * stored access records via HotelAccessService — the route-bound
     * $reservation only selects *which* row is being viewed, it is never
     * itself treated as proof of authorization.
     */
    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.view')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    /**
     * $hotel is the Room Type's own hotel, resolved by the Controller
     * before this call (Phase 3C decision D3) — there is no route-bound
     * Hotel for a Reservation, so the caller must derive it from the
     * chosen room_type_id first. This is the sole place a Hotel Manager's
     * hotel assignment is checked for creation; ReservationService's own
     * hotel derivation remains a separate, authoritative business-
     * invariant check, not an authorization one.
     */
    public function create(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('reservations.manage')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    /**
     * A status transition is a Reservation mutation, so it takes the same
     * `reservations.manage` permission as creation (Reception, holding only
     * `reservations.view`, is denied) plus the hotel-scope check resolved
     * from the user's own access records against the reservation's own
     * hotel_id — never a client-supplied value. Phase 4C keeps the
     * authorization boundary minimal: which *transitions* a role may
     * perform (e.g. only Reception checks guests in) is deferred until
     * those workflows exist, not encoded as a new RBAC matrix now.
     */
    public function transition(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.manage')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    /**
     * Extend Stay is a Reservation mutation like a status transition, so it
     * takes the same permission + hotel-scope shape as transition().
     * Eligibility (checked_in/in_stay only) and availability are business
     * checks owned by ReservationExtensionService, not authorization.
     */
    public function extend(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.manage')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    /**
     * Assigning/moving the physical room is its own Reception permission
     * (approved 2026-09-26) — `reservations.manage` alone does not grant it.
     */
    public function assignRoom(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.assign-room')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    /**
     * The front-desk arrivals/departures/in-house lists for one hotel — a
     * read, gated the same as any other reservation read (`reservations.view`
     * + hotel scope), never the narrower check-in-specific permission.
     */
    public function viewFrontDesk(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('reservations.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }
}
