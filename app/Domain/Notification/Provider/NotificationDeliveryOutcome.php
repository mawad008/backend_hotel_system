<?php

namespace App\Domain\Notification\Provider;

/**
 * Phase 11 — the normalized outcome of a single provider send, as reported
 * by the provider boundary.
 *
 * This is the provider-operation outcome only. It is NOT a NotificationStatus
 * (the delivery lifecycle) and the provider never decides that — the
 * workflow layer maps this onto the state machine.
 */
enum NotificationDeliveryOutcome: string
{
    /** The provider accepted the message for delivery. */
    case Sent = 'sent';

    /** The provider could not deliver it (recoverable — the caller may retry). */
    case Failed = 'failed';

    public static function forDirective(SimulationDirective $directive): self
    {
        return match ($directive) {
            SimulationDirective::Deliver => self::Sent,
            SimulationDirective::Fail => self::Failed,
        };
    }
}
