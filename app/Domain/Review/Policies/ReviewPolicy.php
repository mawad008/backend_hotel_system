<?php

namespace App\Domain\Review\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Review\Models\Review;

/**
 * Staff authorization for the review domain. `reviews.view` = broad
 * front-desk/management read (guests routinely ask staff about a review);
 * `reviews.moderate` = Group Owner / Hotel Manager only — approving or
 * rejecting a guest's public review is a reputational judgment call, not a
 * front-desk operational action (same split Phase 10 used for
 * loyalty.manage).
 *
 * A Guest holds no staff permission in this MVP — the guest-facing
 * create/read path never touches this policy.
 */
class ReviewPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function view(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('reviews.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function moderate(User $user, Review $review): bool
    {
        return $user->hasPermission('reviews.moderate')
            && $this->hotelAccess->canAccessHotel($user, $review->hotel_id);
    }
}
