<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Checkout\Exceptions\CheckoutCurrencyMissingException;
use App\Domain\Checkout\Exceptions\CheckoutNotAllowedException;
use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Repositories\Contracts\CheckoutRepositoryInterface;
use App\Domain\Checkout\StateMachine\CheckoutStateMachine;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Services\PaymentSettlementService;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Services\FolioChargeService;
use App\Domain\StayServices\Services\FolioService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 — the checkout workflow (Phase 0 §8/§9/§12, R20/R26-R31).
 *
 * ══ Absolute architectural rule ══
 * The payment provider is NEVER called while a DB transaction is open.
 * Three explicit stages:
 *
 *   STEP A  (DB txn)  lock Reservation, validate/resume, drive
 *                     IN_STAY -> CHECKOUT_IN_PROGRESS via ReservationService,
 *                     create/reopen the Checkout record, compute the
 *                     authoritative folio (FolioService), snapshot it,
 *                     decide whether a settlement is required, COMMIT.
 *   STEP B  (no txn)  PaymentSettlementService::settle(...) — itself staged,
 *                     the gateway call happens fully outside any transaction.
 *   STEP C  (DB txn)  re-lock Reservation -> Payment -> Checkout, re-read the
 *                     folio, and EITHER finalize (invoice issued, reservation
 *                     CHECKOUT_IN_PROGRESS -> CHECKED_OUT -> INVOICED) when no
 *                     settlement is owed or it SUCCEEDED, OR record the
 *                     pending / failed settlement and leave the reservation
 *                     in the safe non-final CHECKOUT_IN_PROGRESS state.
 *
 * ══ Business rule ══
 * The reservation NEVER becomes CHECKED_OUT / INVOICED while a required
 * final settlement has failed or is still pending.
 *
 * Dependency direction: CheckoutService -> ReservationService,
 * PaymentSettlementService, FolioService, InvoiceService. None of those
 * depend on Checkout. Reservation status changes always go through
 * ReservationService::transitionTo(); Payment status changes always go
 * through PaymentSettlementService.
 *
 * Lock order everywhere: Reservation -> Payment -> Checkout / settlement
 * transaction.
 */
class CheckoutService
{
    /** Reservation statuses from which a checkout attempt is accepted (start or resume). */
    private const CHECKOUTABLE_STATUSES = [
        Reservation::STATUS_IN_STAY,
        Reservation::STATUS_CHECKOUT_IN_PROGRESS,
        Reservation::STATUS_CHECKED_OUT,
    ];

    public function __construct(
        private readonly CheckoutRepositoryInterface $checkouts,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly PaymentRepositoryInterface $payments,
        private readonly ReservationService $reservationService,
        private readonly PaymentSettlementService $settlement,
        private readonly FolioService $folioService,
        private readonly FolioChargeService $folioCharges,
        private readonly InvoiceService $invoiceService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Read the checkout + invoice for a reservation (no mutation).
     */
    public function currentFor(Reservation $reservation): ?CheckoutResult
    {
        $checkout = $this->checkouts->findByReservation($reservation->id);

        if ($checkout === null) {
            return null;
        }

        return $this->result($checkout->id);
    }

    /**
     * The staff settlements ledger for a hotel — a pure read, no workflow
     * decision. A "settlement" is a Checkout row; the domain has no
     * separate Settlement entity.
     *
     * @param  array{status?: string|null}  $filters
     */
    public function listForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->checkouts->paginateForHotel($hotelId, $filters, $perPage);
    }

    /**
     * Perform (or idempotently replay / retry) checkout for a reservation.
     *
     * MUST NOT be called from inside an open DB transaction.
     *
     * @throws ModelNotFoundException if the reservation no longer exists
     * @throws CheckoutNotAllowedException if the reservation state forbids checkout
     * @throws CheckoutCurrencyMissingException if a settlement is required but no currency is known
     */
    public function checkout(
        Reservation $reservation,
        ?string $idempotencyKey = null,
        ?SimulationDirective $directive = null,
        ?User $actor = null,
    ): CheckoutResult {
        $plan = $this->openCheckout($reservation->id, $actor);

        if ($plan['done']) {
            return $this->result($plan['checkoutId']);
        }

        if ($plan['needsSettlement']) {
            // ── STEP B ── provider settlement, OUTSIDE any DB transaction.
            // PaymentSettlementService re-locks and re-reads by id, so the
            // (possibly stale) model passed here is used only for its id.
            $this->settlement->settle(
                reservation: $reservation,
                settlementAmount: $plan['outstanding'],
                currency: $plan['currency'],
                idempotencyKey: $idempotencyKey,
                directive: $directive,
                actor: $actor,
            );
        }

        // ── STEP C ──
        return $this->finalizeCheckout($plan['reservationId'], $plan['checkoutId'], $actor);
    }

    /**
     * STEP A — no provider call. Start or resume checkout and decide whether
     * a final settlement is required.
     *
     * @return array{done: bool, checkoutId: int, reservationId?: int, needsSettlement?: bool, outstanding?: string, chargesTotal?: string, currency?: string|null}
     */
    private function openCheckout(int $reservationId, ?User $actor): array
    {
        return DB::transaction(function () use ($reservationId, $actor) {
            $reservation = $this->reservations->findForUpdate($reservationId);

            if (! $reservation) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservationId]);
            }

            // Lock order Reservation -> Payment -> Checkout (matches STEP C).
            $payment = $this->payments->findByReservationForUpdate($reservationId);
            $checkout = $this->checkouts->findByReservationForUpdate($reservationId);

            // ── Idempotent: already fully checked out + invoiced ──
            if ($reservation->status === Reservation::STATUS_INVOICED) {
                $checkout ??= $this->ensureCheckoutRecord($reservation, $actor);

                if ($checkout->status !== Checkout::STATUS_COMPLETED) {
                    $checkout = $this->setCheckoutStatus($checkout, Checkout::STATUS_COMPLETED, [
                        'completed_at' => $checkout->completed_at ?? now(),
                    ]);
                }

                return ['done' => true, 'checkoutId' => $checkout->id];
            }

            if (! in_array($reservation->status, self::CHECKOUTABLE_STATUSES, true)) {
                throw new CheckoutNotAllowedException($reservation->status);
            }

            // ── Start: IN_STAY -> CHECKOUT_IN_PROGRESS ──
            if ($reservation->status === Reservation::STATUS_IN_STAY) {
                $this->reservationService->transitionTo(
                    $reservation, Reservation::STATUS_CHECKOUT_IN_PROGRESS, $actor,
                );
                $reservation = $this->reservations->findForUpdate($reservationId);
            }

            if ($checkout === null) {
                $checkout = $this->createCheckoutRecord($reservation, $actor);
                $this->auditLogger->record(
                    $actor, 'checkout.started', $checkout,
                    after: $this->auditSnapshot($checkout), hotelId: $checkout->hotel_id,
                );
            } elseif (! in_array($checkout->status, [Checkout::STATUS_IN_PROGRESS, Checkout::STATUS_COMPLETED], true)) {
                // Re-opening a previously pending / failed checkout for a retry.
                $checkout = $this->setCheckoutStatus($checkout, Checkout::STATUS_IN_PROGRESS);
            }

            // ── Accommodation charge (Phase 9 review fix) ──
            // Posted once per reservation into the folio (amount =
            // reservation.price_snapshot used exactly, source_id =
            // reservation.id) so it is part of charges_total / the invoice
            // subtotal / outstanding_total and appears as an invoice item.
            // Idempotent — a checkout retry reuses the same row.
            $this->folioCharges->postAccommodationCharge($reservation, $reservation->currency ?? $payment?->currency, $actor);
            // The booking's service fee (snapshotted at booking) as its own line.
            $this->folioCharges->postServiceFeeCharge($reservation, $reservation->currency ?? $payment?->currency, $actor);

            // ── Authoritative folio ──
            $folio = $this->folioService->folioFor($reservation);
            $currency = $folio->currency;
            $outstanding = (string) $folio->outstandingTotal;
            $chargesTotal = (string) $folio->chargesTotal;

            $needsSettlement = bccomp($outstanding, '0.00', 2) === 1
                && $reservation->status === Reservation::STATUS_CHECKOUT_IN_PROGRESS;

            if ($needsSettlement && $currency === null) {
                throw new CheckoutCurrencyMissingException;
            }

            $checkout = $this->checkouts->update($checkout, [
                'charges_total' => $chargesTotal,
                'payments_total' => (string) $folio->paymentsTotal,
                'outstanding_total' => $outstanding,
                'currency' => $currency,
            ]);

            return [
                'done' => false,
                'checkoutId' => $checkout->id,
                'reservationId' => $reservationId,
                'needsSettlement' => $needsSettlement,
                'outstanding' => $outstanding,
                'chargesTotal' => $chargesTotal,
                'currency' => $currency,
            ];
        });
    }

    /**
     * STEP C — finalize, or record a pending / failed settlement. No
     * provider call.
     */
    private function finalizeCheckout(int $reservationId, int $checkoutId, ?User $actor): CheckoutResult
    {
        return DB::transaction(function () use ($reservationId, $checkoutId, $actor) {
            $reservation = $this->reservations->findForUpdate($reservationId);
            $payment = $this->payments->findByReservationForUpdate($reservationId);
            $checkout = $this->checkouts->findByReservationForUpdate($reservationId);

            if ($reservation === null || $checkout === null) {
                throw (new ModelNotFoundException)->setModel(Checkout::class, [$checkoutId]);
            }

            $folio = $this->folioService->folioFor($reservation);
            $outstanding = (string) $folio->outstandingTotal;

            $settlementTxnId = $this->latestSettlementTransactionId($payment);

            $settlementOwed = bccomp($outstanding, '0.00', 2) === 1
                && $reservation->status === Reservation::STATUS_CHECKOUT_IN_PROGRESS;

            if ($settlementOwed) {
                $outcome = $this->settlementOutcome($payment);

                if ($outcome === 'pending') {
                    $checkout = $this->setCheckoutStatus($checkout, Checkout::STATUS_AWAITING_SETTLEMENT, [
                        'settlement_transaction_id' => $settlementTxnId,
                        'outstanding_total' => $outstanding,
                    ]);
                    $this->auditLogger->record(
                        $actor, 'checkout.settlement_pending', $checkout,
                        after: $this->auditSnapshot($checkout), hotelId: $checkout->hotel_id,
                    );

                    return $this->buildResult($checkout, $payment);
                }

                if ($outcome === 'failed') {
                    $checkout = $this->setCheckoutStatus($checkout, Checkout::STATUS_SETTLEMENT_FAILED, [
                        'settlement_transaction_id' => $settlementTxnId,
                        'outstanding_total' => $outstanding,
                    ]);
                    $this->auditLogger->record(
                        $actor, 'checkout.settlement_failed', $checkout,
                        after: $this->auditSnapshot($checkout) + ['blocked_by' => 'final_settlement'],
                        hotelId: $checkout->hotel_id,
                    );

                    return $this->buildResult($checkout, $payment);
                }
                // $outcome === 'settled' — fall through to finalize.
            }

            // ── FINALIZE ──
            $totals = [
                'charges_total' => (string) $folio->chargesTotal,
                'payments_total' => (string) $folio->paymentsTotal,
                'outstanding_total' => $outstanding,
            ];

            $invoice = $this->invoiceService->finalizeFor($reservation, $totals, $folio->currency, $actor);

            if ($reservation->status === Reservation::STATUS_CHECKOUT_IN_PROGRESS) {
                $this->reservationService->transitionTo($reservation, Reservation::STATUS_CHECKED_OUT, $actor);
                $reservation = $this->reservations->findForUpdate($reservationId);
            }

            if ($reservation->status === Reservation::STATUS_CHECKED_OUT) {
                $this->reservationService->transitionTo($reservation, Reservation::STATUS_INVOICED, $actor);
            }

            $checkout = $this->setCheckoutStatus($checkout, Checkout::STATUS_COMPLETED, [
                'charges_total' => $totals['charges_total'],
                'payments_total' => $totals['payments_total'],
                'outstanding_total' => $outstanding,
                'currency' => $folio->currency,
                'settlement_transaction_id' => $settlementTxnId,
                'completed_at' => now(),
            ]);

            $this->auditLogger->record(
                $actor, 'checkout.completed', $checkout,
                after: $this->auditSnapshot($checkout) + [
                    'invoice_number' => $invoice->invoice_number,
                    'reservation_status' => Reservation::STATUS_INVOICED,
                ],
                hotelId: $checkout->hotel_id,
            );

            return $this->buildResult($checkout, $payment?->fresh());
        });
    }

    /**
     * 'settled' | 'pending' | 'failed' — the effect of the STEP B settlement
     * on the Payment.
     */
    private function settlementOutcome(?Payment $payment): string
    {
        return match ($payment?->status) {
            Payment::STATUS_SETTLED => 'settled',
            Payment::STATUS_FINAL_SETTLEMENT_REQUESTED => 'pending',
            default => 'failed',
        };
    }

    private function latestSettlementTransactionId(?Payment $payment): ?int
    {
        return $payment?->transactions()
            ->where('type', PaymentTransaction::TYPE_SETTLEMENT)
            ->orderByDesc('id')
            ->value('id');
    }

    private function createCheckoutRecord(Reservation $reservation, ?User $actor): Checkout
    {
        try {
            return $this->checkouts->create([
                'reservation_id' => $reservation->id,
                'hotel_id' => $reservation->hotel_id,
                'status' => CheckoutStateMachine::INITIAL_STATUS,
                'started_at' => now(),
                'created_by_user_id' => $actor?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            $winner = $this->checkouts->findByReservationForUpdate($reservation->id);

            return $winner ?? throw (new ModelNotFoundException)->setModel(Checkout::class, [$reservation->id]);
        }
    }

    private function ensureCheckoutRecord(Reservation $reservation, ?User $actor): Checkout
    {
        return $this->checkouts->findByReservationForUpdate($reservation->id)
            ?? $this->createCheckoutRecord($reservation, $actor);
    }

    /**
     * Persist a checkout status change, guarded by CheckoutStateMachine.
     * A no-op when the status is unchanged (extra attributes still applied).
     *
     * @param  array<string, mixed>  $extra
     */
    private function setCheckoutStatus(Checkout $checkout, string $target, array $extra = []): Checkout
    {
        if ($checkout->status !== $target) {
            CheckoutStateMachine::assertCanTransition($checkout->status, $target);
        }

        return $this->checkouts->update($checkout, ['status' => $target] + $extra);
    }

    private function result(int $checkoutId): CheckoutResult
    {
        $checkout = $this->checkouts->find($checkoutId);

        if ($checkout === null) {
            throw (new ModelNotFoundException)->setModel(Checkout::class, [$checkoutId]);
        }

        $payment = $this->payments->findByReservation($checkout->reservation_id);

        return $this->buildResult($checkout, $payment);
    }

    private function buildResult(Checkout $checkout, ?Payment $payment): CheckoutResult
    {
        $checkout->loadMissing('reservation');

        return new CheckoutResult(
            $checkout,
            $this->invoiceService->findForReservation($checkout->reservation),
            $payment,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Checkout $checkout): array
    {
        return array_filter([
            'checkout_status' => $checkout->status,
            'charges_total' => $checkout->charges_total,
            'payments_total' => $checkout->payments_total,
            'outstanding_total' => $checkout->outstanding_total,
            'currency' => $checkout->currency,
            'started_at' => $checkout->started_at?->toIso8601String(),
            'completed_at' => $checkout->completed_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
