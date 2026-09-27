<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Inventory\Models\RoomType;

class RoomTypePolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    /**
     * Room Types are always listed nested under one hotel
     * (/hotels/{hotel}/room-types), so — unlike Hotel's own top-level
     * viewAny — this needs the target Hotel to check access against.
     */
    public function viewAny(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('inventory.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    /**
     * The hotel scope check is resolved from the authenticated user's
     * own stored access records via HotelAccessService — the route-bound
     * $roomType only selects *which* row is being viewed, it is never
     * itself treated as proof of authorization.
     */
    public function view(User $user, RoomType $roomType): bool
    {
        return $user->hasPermission('inventory.view')
            && $this->hotelAccess->canAccessHotel($user, $roomType->hotel_id);
    }

    public function create(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('inventory.manage')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function update(User $user, RoomType $roomType): bool
    {
        return $user->hasPermission('inventory.manage')
            && $this->hotelAccess->canAccessHotel($user, $roomType->hotel_id);
    }

    /**
     * Managing a room type's images is a room-type-edit action: the same
     * `inventory.manage` permission as update, plus the hotel-scope check
     * resolved from the user's own stored access records.
     */
    public function manageMedia(User $user, RoomType $roomType): bool
    {
        return $user->hasPermission('inventory.manage')
            && $this->hotelAccess->canAccessHotel($user, $roomType->hotel_id);
    }
}
