<?php

namespace App\Domain\IdentityVerification\Policies;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 6 — authorization for the identity verification HTTP surface
 * (Phase 0 §7).
 *
 * §7 matrix: "Review/decide pending verification" is ✅ for Group Owner,
 * Hotel Manager AND Reception, ❌ for Guest. Verification is not a financial
 * action, so Reception is included on every capability here (it is the
 * check-in support/fallback layer — R7, R33 "processing-on-arrival").
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
class IdentityVerificationPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('identity-verification.view')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    public function submit(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('identity-verification.submit')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    public function review(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('identity-verification.review')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }
}
