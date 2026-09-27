<?php

namespace App\Domain\Audit\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;

/**
 * `audit.view` = Group Owner, Hotel Manager only — reading the audit trail
 * is oversight, not a front-desk operational action (same split Phase 10/
 * Review used for loyalty.manage / reviews.moderate). Reception holds no
 * audit.view permission at all.
 */
class AuditLogPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function viewForHotel(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('audit.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    /**
     * The group-wide feed (every hotel, plus hotel-independent events like
     * user/role management) — Group Owner only, since it crosses hotel
     * boundaries a Hotel Manager's own access never does.
     */
    public function viewGlobal(User $user): bool
    {
        return $user->hasPermission('audit.view') && $user->isGroupOwner();
    }
}
