<?php

namespace App\Domain\Loyalty\Listeners;

use App\Domain\Loyalty\Exceptions\LoyaltyNotAllowedException;
use App\Domain\Loyalty\Services\LoyaltyService;
use App\Domain\Reservation\Events\ReservationStatusChanged;
use Throwable;

/**
 * Lifecycle-driven accrual: when a stay completes (checked_out / invoiced)
 * the guest earns their points automatically through the existing,
 * idempotent LoyaltyService::earnForReservation — the same rule, rate and
 * eligibility checks the staff "earn" action uses, so a stay never accrues
 * twice whichever path runs first.
 *
 * Runs on the post-commit ReservationStatusChanged seam, so a loyalty
 * problem can never roll back or block a checkout. A program that is
 * inactive / has no earn rate for the group (LoyaltyNotAllowedException) is
 * a valid configuration, not an error, and is skipped silently; anything
 * else is reported.
 */
class AccrueLoyaltyOnStayCompletion
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    public function handle(ReservationStatusChanged $event): void
    {
        if (! in_array($event->toStatus(), LoyaltyService::COMPLETED_RESERVATION_STATUSES, true)) {
            return;
        }

        try {
            $this->loyalty->earnForReservation($event->reservation->fresh() ?? $event->reservation, $event->actor);
        } catch (LoyaltyNotAllowedException) {
            // Program inactive / no earn rate / no eligible value — nothing to accrue.
        } catch (Throwable $e) {
            report($e);
        }
    }
}
