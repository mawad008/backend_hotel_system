<?php

namespace App\Domain\StayServices\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\StayServices\Models\ServiceReview;

/**
 * Staff authorization for the service-review domain. Reuses the hotel
 * review permissions (`reviews.view` / `reviews.moderate`) rather than
 * introducing a parallel permission pair — a service review is the same
 * kind of staff decision (approve/reject guest-authored content) as a
 * hotel review, just scoped to a service instead of a stay.
 */
class ServiceReviewPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function view(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('reviews.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function moderate(User $user, ServiceReview $review): bool
    {
        return $user->hasPermission('reviews.moderate')
            && $this->hotelAccess->canAccessHotel($user, $review->hotel_id);
    }
}
