<?php

namespace App\Domain\StayServices\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\StayServices\Models\ServiceCategory;

/**
 * Phase 8E — authorization for the service-category catalog surface
 * (Phase 0 §7).
 *
 * `services.view` = read the catalog (Group Owner, Hotel Manager,
 * Reception — Reception needs it to assist guests).
 * `services.manage` = configure the catalog. Setting service pricing is a
 * financial-configuration action; per §7/§32 Reception has no financial
 * edit capability, so manage is Group Owner + Hotel Manager only.
 *
 * The hotel-scope check is always resolved from the user's own stored
 * access records via HotelAccessService — never a client-supplied hotel_id.
 */
class ServiceCategoryPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function viewAny(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('services.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function view(User $user, ServiceCategory $category): bool
    {
        return $user->hasPermission('services.view')
            && $this->hotelAccess->canAccessHotel($user, $category->hotel_id);
    }

    public function create(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('services.manage')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function update(User $user, ServiceCategory $category): bool
    {
        return $user->hasPermission('services.manage')
            && $this->hotelAccess->canAccessHotel($user, $category->hotel_id);
    }
}
