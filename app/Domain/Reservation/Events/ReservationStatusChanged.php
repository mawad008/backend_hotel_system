<?php

namespace App\Domain\Reservation\Events;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 11 — emitted by ReservationService::transitionTo AFTER its database
 * transaction has committed, once per successful Reservation status change.
 *
 * This is the single, minimal integration seam between the Reservation
 * workflow (Phase 4) and downstream side effects. It carries no behaviour —
 * a listener decides what, if anything, a given transition means. The
 * Reservation domain has no dependency on any listener.
 *
 * The Reservation model is the fresh, post-transition record. `$fromStatus`
 * is the authoritative status the locked re-read started from.
 */
class ReservationStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly string $fromStatus,
        public readonly ?User $actor = null,
        public readonly ?string $locale = null,
    ) {}

    public function toStatus(): string
    {
        return $this->reservation->status;
    }
}
