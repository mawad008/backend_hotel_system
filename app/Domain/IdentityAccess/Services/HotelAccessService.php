<?php

namespace App\Domain\IdentityAccess\Services;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Repositories\Contracts\UserRepositoryInterface;

/**
 * Single point of truth for resolving which hotels an authenticated user
 * may act upon. Group Owner all-hotels access is an explicit bypass here,
 * never an absence of scoping. Every check is resolved from the user's
 * own stored role/assignments — a caller must never pass a client-supplied
 * hotel_id in place of the authenticated user to short-circuit this.
 */
class HotelAccessService
{
    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function canAccessHotel(User $user, int $hotelId): bool
    {
        if ($user->isGroupOwner()) {
            return true;
        }

        return $user->hotels()->whereKey($hotelId)->exists();
    }

    /**
     * @return array<int>
     */
    public function authorizedHotelIds(User $user): array
    {
        return $user->authorizedHotelIds();
    }

    /**
     * @param  array<int>  $hotelIds
     */
    public function syncHotelAccess(User $user, array $hotelIds): void
    {
        $this->users->syncHotelAccess($user, $hotelIds);
    }
}
