<?php

namespace App\Domain\IdentityAccess\Exceptions;

use RuntimeException;

/**
 * Thrown when a role cannot be deleted: it is one of the four protected
 * system roles, or it is still assigned to one or more users. In both cases
 * the caller must act first (reassign users, or simply not delete a system
 * role) — there is no silent cascade/reassignment.
 */
class RoleDeletionBlockedException extends RuntimeException
{
    public static function systemRole(): self
    {
        return new self((string) __('api.role.system_delete_blocked'));
    }

    public static function assignedToUsers(): self
    {
        return new self((string) __('api.role.assigned_delete_blocked'));
    }
}
