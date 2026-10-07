<?php

namespace App\Domain\Notification\Policies;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Services\StaffNotificationService;

/**
 * The staff inbox is recipient-scoped (a user only sees their own rows), so
 * the only gate is the permission itself.
 */
class StaffNotificationPolicy
{
    public function viewInbox(User $user): bool
    {
        return $user->hasPermission(StaffNotificationService::PERMISSION);
    }
}
