<?php

namespace App\Domain\StayServices\Services;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentTransactionRepositoryInterface;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Repositories\Contracts\FolioChargeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Phase 8D — read-only folio maths for a reservation. Deterministic, all
 * DECIMAL-safe (bcmath, never float).
 *
 *   charges_total     = SUM(folio_charges.total_amount WHERE status = posted)
 *                       — includes the Phase 9 accommodation charge and every
 *                       posted service-order charge.
 *   payments_total    = SUM(payment_transactions.amount) over the payment's
 *                       SUCCEEDED capture + settlement transactions
 *                       (Phase 9 review fix). The payment history is the
 *                       source of truth; `payments.amount` (the deposit
 *                       requested at booking) is never used here and never
 *                       overwritten. A hold / pending / failed attempt
 *                       collects nothing and counts as zero.
 *   outstanding_total = charges_total - payments_total
 *
 * This phase does NOT implement checkout, settlement, capture or invoicing;
 * it only exposes the clean data those later phases consume.
 */
class FolioService
{
    public function __construct(
        private readonly FolioChargeRepositoryInterface $charges,
        private readonly PaymentRepositoryInterface $payments,
        private readonly PaymentTransactionRepositoryInterface $paymentTransactions,
        private readonly ReservationRepositoryInterface $reservations,
    ) {}

    public function folioFor(Reservation $reservation): Folio
    {
        $charges = $this->charges->allForReservation($reservation->id);
        $payment = $this->payments->findByReservation($reservation->id);

        $chargesTotal = $this->charges->sumTotalForReservation(
            $reservation->id,
            FolioCharge::OWED_STATUSES,
        );

        $paymentsTotal = $payment === null
            ? '0.00'
            : $this->paymentTransactions->sumCollectedForPayment($payment->id);
        $outstanding = bcsub($chargesTotal, $paymentsTotal, 2);

        return new Folio(
            reservation: $reservation,
            charges: $charges,
            payment: $payment,
            chargesTotal: $chargesTotal,
            paymentsTotal: $paymentsTotal,
            outstandingTotal: $outstanding,
            currency: $this->resolveCurrency($charges, $payment, $reservation->currency),
        );
    }

    /**
     * The standalone folio ledger for a hotel — every reservation with a
     * live folio, newest check-in first, optionally narrowed to a guest/
     * reservation-id search and/or "outstanding balance only".
     *
     * Returns a paginator of Reservation, NOT of Folio: computing the
     * exact authoritative Folio (this class's own folioFor()) for a whole
     * hotel's history would mean one query set per reservation, which does
     * not scale. Instead, "outstanding only" is decided by two single
     * grouped-SUM queries (charges vs. collected payments, per
     * reservation) — cheap, indexed, and reusing the exact same status
     * constants folioFor() itself reads (FolioCharge::OWED_STATUSES /
     * PaymentTransaction::COLLECTED_TYPES via the repositories below), so
     * it can never diverge from what folioFor() would compute. The
     * caller is expected to call folioFor() on each row of the returned
     * (bounded, paginated) page to render the authoritative totals.
     *
     * @param  array{search?: string|null, outstanding_only?: bool}  $filters
     * @return LengthAwarePaginator<Reservation>
     */
    public function listForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        $listFilters = ['search' => $filters['search'] ?? null];

        if (! empty($filters['outstanding_only'])) {
            $listFilters['reservation_ids'] = $this->outstandingReservationIdsForHotel($hotelId);
        }

        return $this->reservations->paginateForFolioLedger($hotelId, $listFilters, $perPage);
    }

    /**
     * Reservation ids of $hotelId whose charges_total (OWED_STATUSES)
     * exceeds their collected payments_total (COLLECTED_TYPES, succeeded)
     * — the exact folioFor() formula, evaluated for every reservation via
     * two grouped SUMs instead of N folioFor() calls.
     *
     * @return list<int>
     */
    private function outstandingReservationIdsForHotel(int $hotelId): array
    {
        $chargesByReservation = $this->charges->sumsGroupedByReservationForHotel($hotelId, FolioCharge::OWED_STATUSES);
        $paymentsByReservation = $this->paymentTransactions->collectedSumsGroupedByReservationForHotel($hotelId);

        $ids = [];

        foreach ($chargesByReservation as $reservationId => $chargesTotal) {
            $paid = $paymentsByReservation[$reservationId] ?? '0.00';

            if (bccomp($chargesTotal, $paid, 2) > 0) {
                $ids[] = (int) $reservationId;
            }
        }

        return $ids;
    }

    /**
     * The reservation's own snapshotted currency; for rows booked before
     * the snapshot existed, the payment's, else the first charge's.
     *
     * @param  Collection<int, FolioCharge>  $charges
     */
    private function resolveCurrency($charges, ?Payment $payment, ?string $reservationCurrency = null): ?string
    {
        if ($reservationCurrency !== null && $reservationCurrency !== '') {
            return $reservationCurrency;
        }

        if ($payment?->currency !== null && $payment->currency !== '') {
            return $payment->currency;
        }

        foreach ($charges as $charge) {
            if ($charge->currency !== null && $charge->currency !== '') {
                return $charge->currency;
            }
        }

        return null;
    }
}
