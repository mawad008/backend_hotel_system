<?php

namespace App\Domain\StayServices\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\StayServices\Models\HotelService;

/**
 * Phase 8E — authorization for the hotel-service catalog surface
 * (Phase 0 §7). Same permission split as ServiceCategoryPolicy:
 * `services.view` to read, `services.manage` (Group Owner + Hotel Manager
 * only) to configure — service pricing is financial configuration and
 * Reception holds no financial-edit capability (§32).
 */
class HotelServicePolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function viewAny(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('services.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function view(User $user, HotelService $service): bool
    {
        return $user->hasPermission('services.view')
            && $this->hotelAccess->canAccessHotel($user, $service->hotel_id);
    }

    public function create(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('services.manage')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function update(User $user, HotelService $service): bool
    {
        return $user->hasPermission('services.manage')
            && $this->hotelAccess->canAccessHotel($user, $service->hotel_id);
    }
}
