<?php

namespace App\Domain\Support\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Support\Models\ProblemReport;

/**
 * Staff authorization for guest problem reports. Unlike Review moderation
 * (a reputational judgment call), triaging a problem report is a routine
 * front-desk/operational action, so `problems.view` + `problems.manage` are
 * both granted to Reception, not reserved for Group Owner / Hotel Manager.
 *
 * A Guest holds no staff permission in this MVP — the guest-facing
 * submit/read path never touches this policy.
 */
class ProblemReportPolicy
{
    public function __construct(private readonly HotelAccessService $hotelAccess) {}

    public function view(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('problems.view')
            && $this->hotelAccess->canAccessHotel($user, $hotel->id);
    }

    public function manage(User $user, ProblemReport $report): bool
    {
        return $user->hasPermission('problems.manage')
            && $this->hotelAccess->canAccessHotel($user, $report->hotel_id);
    }
}
