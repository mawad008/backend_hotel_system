<?php

namespace App\Domain\Notification\Enums;

/**
 * Phase 11 — the delivery lifecycle status of one notification row.
 *
 * The transition rules between these values live ONLY in
 * NotificationDeliveryStateMachine — never in the model, a service, or a
 * test. This mirrors every other Phase 5-10 state machine.
 *
 *   PENDING ──► SENDING ──► SENT (terminal)
 *                    │
 *                    └────► FAILED ──► SENDING (safe retry)
 */
enum NotificationStatus: string
{
    /** The row is persisted; the provider has not been called yet. */
    case Pending = 'pending';

    /** The staged provider call is in flight (mirrors Payment HOLD_REQUESTED). */
    case Sending = 'sending';

    /** The provider accepted the message. Terminal. */
    case Sent = 'sent';

    /** The provider could not deliver. Recoverable — a retry re-enters SENDING. */
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
