<?php

namespace App\Domain\Loyalty\Policies;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;

/**
 * Phase 10 — authorization for loyalty-rule configuration.
 *
 * Phase 0 §7: "Configure loyalty/cancellation/pricing/verification-threshold
 * rules" is ✅ for Group Owner and ❌ for everyone else. `loyalty.rules.manage`
 * is granted to Group Owner only.
 */
class LoyaltyRulePolicy
{
    public function view(User $user, HotelGroup $group): bool
    {
        return $user->hasPermission('loyalty.rules.manage');
    }

    public function manage(User $user, HotelGroup $group): bool
    {
        return $user->hasPermission('loyalty.rules.manage');
    }
}
