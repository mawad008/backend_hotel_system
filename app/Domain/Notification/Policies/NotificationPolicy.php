<?php

namespace App\Domain\Notification\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 11 — authorization for the reservation-scoped notification feed
 * (Phase 0 §7/§9).
 *
 * `notifications.view` gates the whole feed (list + mark-read). Reading a
 * guest's notification history and clearing its unread markers is a routine
 * front-desk support action with no financial effect — so it follows the
 * same Owner / Manager / Reception split as `folio.view` / `loyalty.view`.
 * A Guest holds no staff permission in this MVP and never resolves the
 * reservation scope at all (plain 404).
 *
 * The $reservation is resolved by the controller through the approved
 * Reservation access path first; hotel scope is resolved here from the
 * user's own stored access records via HotelAccessService — never a
 * client-supplied hotel_id.
 */
class NotificationPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function viewAny(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('notifications.view')
            && $this->hotelAccess->canAccessHotel($user, $reservation->hotel_id);
    }

    public function markRead(User $user, Reservation $reservation): bool
    {
        return $this->viewAny($user, $reservation);
    }

    /**
     * The staff-wide notification feed for a hotel — same `notifications.view`
     * permission as the reservation-scoped feed, just hotel-wide.
     */
    public function viewAnyForHotel(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('notifications.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }
}
