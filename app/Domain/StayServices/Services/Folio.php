<?php

namespace App\Domain\StayServices\Services;

use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use Illuminate\Support\Collection;

/**
 * Phase 8D — the read model a folio endpoint (and, later, checkout)
 * consumes. It is NOT a persisted entity: a folio is exactly "one
 * reservation + its folio charges + the money maths", derived on read.
 *
 * All money values are canonical DECIMAL(12,2) strings. Nothing here
 * exposes card data or a provider secret.
 */
final class Folio
{
    /**
     * @param  Collection<int, FolioCharge>  $charges
     */
    public function __construct(
        public readonly Reservation $reservation,
        public readonly Collection $charges,
        public readonly ?Payment $payment,
        public readonly string $chargesTotal,
        public readonly string $paymentsTotal,
        public readonly string $outstandingTotal,
        public readonly ?string $currency,
    ) {}

    /**
     * Whether the reservation's payment represents money actually
     * captured/settled (not a mere authorization hold, not pending, not
     * failed).
     */
    public function isPaymentCaptured(): bool
    {
        return $this->payment !== null
            && in_array($this->payment->status, Payment::CAPTURED_STATUSES, true);
    }
}
