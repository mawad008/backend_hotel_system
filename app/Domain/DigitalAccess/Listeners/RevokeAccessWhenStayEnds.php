<?php

namespace App\Domain\DigitalAccess\Listeners;

use App\Domain\DigitalAccess\Exceptions\DigitalAccessActionNotAllowedException;
use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\Reservation\Events\ReservationStatusChanged;
use App\Domain\Reservation\Models\Reservation;
use Throwable;

/**
 * A room credential must stop working the moment the stay is over: when a
 * reservation reaches checked_out / invoiced / cancelled, an ACTIVE grant is
 * revoked through the existing, idempotent DigitalAccessService::revoke —
 * the same path the front desk's "revoke" uses (provider call + audit).
 *
 * Runs on the post-commit ReservationStatusChanged seam, so an access-provider
 * problem can never roll back or block a checkout or cancellation. A
 * reservation that never had an active grant is not an error.
 */
class RevokeAccessWhenStayEnds
{
    public const ENDED_STATUSES = [
        Reservation::STATUS_CHECKED_OUT,
        Reservation::STATUS_INVOICED,
        Reservation::STATUS_CANCELLED,
    ];

    public function __construct(private readonly DigitalAccessService $access) {}

    public function handle(ReservationStatusChanged $event): void
    {
        if (! in_array($event->toStatus(), self::ENDED_STATUSES, true)) {
            return;
        }

        try {
            $this->access->revoke(
                $event->reservation,
                reason: 'stay_ended:'.$event->toStatus(),
                actor: $event->actor,
            );
        } catch (DigitalAccessActionNotAllowedException) {
            // No grant was ever issued, or it is not active — nothing to revoke.
        } catch (Throwable $e) {
            report($e);
        }
    }
}
