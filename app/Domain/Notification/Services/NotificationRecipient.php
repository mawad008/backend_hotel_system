<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationRecipientType;
use App\Domain\Reservation\Models\Guest;

/**
 * Phase 11 — a resolved notification recipient (Phase 0 §7 STEP 7 "recipient
 * identity must be resolved server-side").
 *
 * Built ONLY from a server-side relationship (a Reservation's Guest) — never
 * from a client-supplied id or address. The contact values live here just
 * long enough to build a provider routing token; they are never persisted
 * on the notification row.
 */
final class NotificationRecipient
{
    public function __construct(
        public readonly NotificationRecipientType $type,
        public readonly int $id,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly bool $externalChannelsEnabled = true,
    ) {}

    public static function fromGuest(Guest $guest): self
    {
        return new self(
            type: NotificationRecipientType::Guest,
            id: $guest->id,
            email: $guest->email,
            phone: $guest->phone,
            // PROFILE_Preferences "الإشعارات": opting out stops email/SMS;
            // the in-app feed is the app itself and always keeps the record.
            externalChannelsEnabled: $guest->resolvedPreferences()['notifications_enabled'],
        );
    }

    /**
     * Whether this recipient can be reached on the given channel. `in_app` is
     * always reachable (it is an internal feed); `email` / `sms` require the
     * corresponding contact value.
     */
    public function canReceiveOn(NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::InApp => true,
            NotificationChannel::Email => $this->externalChannelsEnabled && filled($this->email),
            NotificationChannel::Sms => $this->externalChannelsEnabled && filled($this->phone),
        };
    }

    /**
     * An OPAQUE, non-PII routing token for the provider. It is a hash of the
     * recipient identity + channel — deterministic, reversible only by a
     * real adapter's own secure lookup, and safe to log.
     */
    public function destinationReference(NotificationChannel $channel): string
    {
        return 'rcpt_'.substr(
            hash('sha256', $this->type->value.'|'.$this->id.'|'.$channel->value),
            0,
            32,
        );
    }
}
