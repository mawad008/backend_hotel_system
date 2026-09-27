<?php

namespace App\Domain\Review\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Review\Models\ReviewCategory;

/**
 * Review categories are hotel configuration: listing needs `reviews.view`,
 * any change needs `review-categories.manage` — always within the user's
 * hotel scope.
 */
class ReviewCategoryPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function viewAny(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('reviews.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function create(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('review-categories.manage')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function update(User $user, ReviewCategory $category): bool
    {
        return $user->hasPermission('review-categories.manage')
            && $this->hotelAccess->canAccessHotel($user, $category->hotel_id);
    }

    public function delete(User $user, ReviewCategory $category): bool
    {
        return $this->update($user, $category);
    }
}
