<?php

namespace App\Domain\Notification\Enums;

/**
 * Phase 11 — who a notification is addressed to.
 *
 * Only `guest` is produced this phase (every implemented notification is a
 * reservation milestone for the booking's guest). `staff_user` is reserved
 * in the enum — following the loyalty ledger's reserved-type precedent — so
 * a future staff-facing notification (e.g. the manual-review queue) reuses
 * this table with no schema change. It is never written this phase.
 */
enum NotificationRecipientType: string
{
    case Guest = 'guest';

    case StaffUser = 'staff_user';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
