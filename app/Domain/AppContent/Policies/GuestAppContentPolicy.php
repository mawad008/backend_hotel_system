<?php

namespace App\Domain\AppContent\Policies;

use App\Domain\AppContent\Models\GuestAppContent;
use App\Domain\IdentityAccess\Models\User;

/**
 * Staff management of the Guest App's branding / entry content. App-wide
 * (not hotel-scoped), so it is a group-level capability. The anonymous
 * guest read (`GET /guest/app-content`) needs no policy.
 */
class GuestAppContentPolicy
{
    public function view(User $user, GuestAppContent $content): bool
    {
        return $user->hasPermission('app-content.manage');
    }

    public function update(User $user, GuestAppContent $content): bool
    {
        return $user->hasPermission('app-content.manage');
    }
}
