<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Services\NotificationRecipient;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Reservation\Events\ReservationStatusChanged;
use Throwable;

/**
 * Phase 11 — translates a committed Reservation status change into the
 * notification(s) the approved guest journey calls for (R20 + R33). This is
 * the notification domain's inbound seam; the Reservation domain never
 * depends on it.
 *
 * Runs synchronously (the project has no queue yet — see NotificationService
 * for the future-enhancement note). It is deliberately defensive: a
 * notification is a side effect, and a delivery problem must NEVER roll back
 * or block the business transition that already committed. Any failure here
 * is reported and swallowed.
 *
 * Only transitions that map to a NotificationType (via
 * NotificationType::forReservationTransition) produce anything — every other
 * transition is a no-op with zero writes.
 */
class SendReservationLifecycleNotifications
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(ReservationStatusChanged $event): void
    {
        $type = NotificationType::forReservationTransition($event->fromStatus, $event->toStatus());

        if ($type === null) {
            return;
        }

        try {
            $reservation = $event->reservation;
            $guest = $reservation->guest()->first();

            if ($guest === null) {
                return;
            }

            $this->notifications->dispatchForReservation(
                type: $type,
                reservation: $reservation,
                recipient: NotificationRecipient::fromGuest($guest),
                eventKey: 'reservation:'.$reservation->id.':'.$event->toStatus(),
                context: [
                    'from_status' => $event->fromStatus,
                    'to_status' => $event->toStatus(),
                ],
                locale: $event->locale,
                actor: $event->actor,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
