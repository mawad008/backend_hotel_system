<?php

namespace App\Domain\Notification\Enums;

/**
 * Phase 11 — a logical delivery channel (Phase 0 §15: "email/SMS/push, one
 * adapter per channel").
 *
 * These are LOGICAL channels only this phase. `in_app` is the reservation
 * notification feed (a real, queryable record). `email` and `sms` are
 * simulated end-to-end by the deterministic dummy provider — no real
 * message is sent, no external SDK or credential is introduced. WhatsApp is
 * deliberately absent (not in the approved requirements).
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';

    case Email = 'email';

    case Sms = 'sms';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
