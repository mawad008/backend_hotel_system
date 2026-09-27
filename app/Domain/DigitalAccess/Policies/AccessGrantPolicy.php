<?php

namespace App\Domain\DigitalAccess\Policies;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 7 — authorization for the check-in / digital access HTTP surface
 * (Phase 0 §7).
 *
 * §7 matrix: "Issue/revoke digital access" is ✅ for Group Owner and Hotel
 * Manager, and "manual-assist, logged" for Reception; a Guest gets
 * "system-issued only" (never a manual issue/revoke). Check-in is the
 * guest-facing digital check-in path (R14-R19), with Reception as the
 * support/fallback layer (R7). Digital access is not a financial action, so
 * Reception is included on every capability here — every action is audited.
 *
 * Guest authentication does not exist in this MVP, so a Guest-owns-their-own
 * rule cannot be expressed; Guest-role users simply lack the permissions and
 * are denied like any other unpermissioned role.
 *
 * The $reservation is resolved by the controller through the approved
 * Reservation access path before any of these calls; it only selects which
 * row the action is for and is never itself treated as proof of
 * authorization — the hotel-scope check is resolved through
 * HotelAccessService from the user's own stored access records.
 */
class AccessGrantPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function checkIn(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('check-in.perform')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('digital-access.view')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    public function revoke(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('digital-access.revoke')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }
}
