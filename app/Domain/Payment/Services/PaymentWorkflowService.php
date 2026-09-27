<?php

namespace App\Domain\Payment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Payment\Exceptions\IdempotencyKeyConflictException;
use App\Domain\Payment\Exceptions\InvalidPaymentAmountException;
use App\Domain\Payment\Exceptions\InvalidPaymentCurrencyException;
use App\Domain\Payment\Exceptions\PaymentAlreadyInitiatedException;
use App\Domain\Payment\Exceptions\PaymentHoldNotAllowedException;
use App\Domain\Payment\Exceptions\PaymentRefundFailedException;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\Data\GatewayHoldRequest;
use App\Domain\Payment\Gateway\Data\GatewayOperationRequest;
use App\Domain\Payment\Gateway\Data\GatewayResult;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentTransactionRepositoryInterface;
use App\Domain\Payment\StateMachine\PaymentStateMachine;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Reservation\Services\ReservationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 5C — the deposit/hold payment workflow and its integration with the
 * Reservation workflow (Phase 0 §8/§9, R26–R31).
 *
 * This is the first phase where Payment is a real business workflow. It
 * owns hold initiation only — capture, settlement, refund, reconciliation
 * and the webhook HTTP boundary are later phases.
 *
 * ══ Absolute architectural rule (Phase 5C §3) ══
 * The provider (PaymentGatewayInterface) is NEVER called while a database
 * transaction is open. The flow is three explicit stages:
 *
 *   STEP A  (DB transaction)   lock Reservation, validate, create/find
 *                              Payment (HOLD_REQUESTED) + PaymentTransaction
 *                              (pending), audit, COMMIT.
 *   STEP B  (no transaction)   PaymentGatewayInterface::initiateHold(...).
 *   STEP C  (DB transaction)   re-lock Reservation -> Payment ->
 *                              PaymentTransaction, re-read, apply the
 *                              provider result, transition the Reservation
 *                              through ReservationService when appropriate,
 *                              audit, COMMIT.
 *
 * The A→B→C boundary is deliberately not one ACID unit: a crash between A
 * and C leaves a persisted pending PaymentTransaction that the future
 * webhook/reconciliation phase (5E/5F) resolves.
 *
 * Dependency direction: PaymentWorkflowService -> ReservationService.
 * ReservationService never depends on Payment. Reservation status changes
 * always go through ReservationService::transitionTo(), which is the only
 * component allowed to drive ReservationStateMachine.
 */
class PaymentWorkflowService
{
    /**
     * Payment statuses from which a fresh hold may be (re)initiated while
     * the Reservation is still PENDING. HOLD_FAILED is absent on purpose:
     * a failed hold cancels the Reservation (§14), so it can never be
     * PENDING when we look.
     *
     * @var list<string>
     */
    private const HOLD_STARTABLE_PAYMENT_STATUSES = [
        Payment::STATUS_NOT_STARTED,
    ];

    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentRepositoryInterface $payments,
        private readonly PaymentTransactionRepositoryInterface $transactions,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly ReservationService $reservationService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Initiate (or idempotently replay) a deposit hold for a PENDING
     * Reservation.
     *
     * Returns the Payment in whatever state the provider result produced —
     * a declined/pending provider outcome is a returned state, not an
     * exception. Exceptions are reserved for "this hold may not be
     * started at all" (wrong Reservation state, idempotency conflict,
     * invalid amount/currency).
     *
     * MUST NOT be called from inside an open DB transaction (§3).
     *
     * @param  int|float|string  $amount  a positive value fitting DECIMAL(12,2); supplied by the caller — Phase 5C invents no pricing
     * @param  string|null  $currency  ISO-4217 alpha code, or null to fall back to config('payment.currency') (which may itself be null — never invented)
     * @param  string|null  $idempotencyKey  the caller's key; a fresh UUID is used when null
     *
     * @throws ModelNotFoundException if the Reservation no longer exists
     * @throws PaymentHoldNotAllowedException if the Reservation is not PENDING
     * @throws PaymentAlreadyInitiatedException if a non-replayable Payment already exists
     * @throws IdempotencyKeyConflictException if the key was used for a different operation
     * @throws InvalidPaymentAmountException|InvalidPaymentCurrencyException
     */
    public function initiateHold(
        Reservation $reservation,
        int|float|string $amount,
        ?string $currency = null,
        ?string $idempotencyKey = null,
        ?SimulationDirective $simulationDirective = null,
        ?User $actor = null,
    ): Payment {
        $reservationId = $reservation->id;
        $amountValue = $this->normalizeAmount($amount);
        $currencyCode = $this->resolveCurrency($currency);
        $key = $idempotencyKey ?? (string) Str::uuid();
        $provider = (string) config('payment.provider');

        $stepA = $this->openHoldAttempt(
            $reservationId,
            $amountValue,
            $currencyCode,
            $key,
            $provider,
            $simulationDirective,
            $actor,
        );

        if ($stepA['replay']) {
            // Step A determined this is an idempotent retry — the earlier
            // attempt already made (or will make) the single provider call.
            $payment = $this->payments->find($stepA['paymentId']);

            if ($payment === null) {
                throw (new ModelNotFoundException)->setModel(Payment::class, [$stepA['paymentId']]);
            }

            return $payment;
        }

        // ── STEP B ── provider call, OUTSIDE any DB transaction (§3, §11).
        $result = $this->gateway->initiateHold(new GatewayHoldRequest(
            intentReference: $key,
            amount: $amountValue,
            currency: $currencyCode,
            directive: $simulationDirective,
        ));

        // ── STEP C ──
        return $this->applyHoldResult(
            $stepA['reservationId'],
            $stepA['paymentId'],
            $stepA['transactionId'],
            $result,
            $actor,
        );
    }

    /**
     * STEP A — no provider call. Everything here is inside one transaction
     * that either fully commits or fully rolls back.
     *
     * @return array{replay: bool, reservationId: int, paymentId: int, transactionId: int|null}
     */
    private function openHoldAttempt(
        int $reservationId,
        string $amountValue,
        ?string $currencyCode,
        string $key,
        string $provider,
        ?SimulationDirective $simulationDirective,
        ?User $actor,
    ): array {
        return DB::transaction(function () use (
            $reservationId,
            $amountValue,
            $currencyCode,
            $key,
            $provider,
            $simulationDirective,
            $actor,
        ) {
            $reservation = $this->reservations->findForUpdate($reservationId);

            if (! $reservation) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservationId]);
            }

            // Idempotent replay: the key already carries a hold attempt.
            $existing = $this->transactions->findByIdempotencyKey($key);

            if ($existing !== null) {
                $this->assertIdempotentMatch($existing, $reservation, $provider);

                return [
                    'replay' => true,
                    'reservationId' => $reservationId,
                    'paymentId' => $existing->payment_id,
                    'transactionId' => $existing->id,
                ];
            }

            if ($reservation->status !== Reservation::STATUS_PENDING) {
                throw new PaymentHoldNotAllowedException($reservation->status);
            }

            $payment = $this->resolvePaymentForNewHold($reservation, $amountValue, $currencyCode);

            try {
                $transaction = $this->transactions->create([
                    'payment_id' => $payment->id,
                    'type' => PaymentTransaction::TYPE_HOLD,
                    'status' => PaymentTransaction::STATUS_PENDING,
                    'idempotency_key' => $key,
                    'provider' => $provider,
                    'provider_reference' => null,
                    'requested_by_user_id' => $actor?->id,
                    'metadata' => $this->initialTransactionMetadata($simulationDirective),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent request inserted the same idempotency key
                // first (§27). The UNIQUE constraint is authoritative —
                // re-read and treat this call as an idempotent replay.
                $winner = $this->transactions->findByIdempotencyKey($key);

                if ($winner === null) {
                    throw new PaymentAlreadyInitiatedException($payment->status);
                }

                return [
                    'replay' => true,
                    'reservationId' => $reservationId,
                    'paymentId' => $winner->payment_id,
                    'transactionId' => $winner->id,
                ];
            }

            $this->auditLogger->record(
                $actor,
                'payment.hold_requested',
                $payment,
                after: $this->auditSnapshot($payment, $transaction),
                hotelId: $reservation->hotel_id,
            );

            return [
                'replay' => false,
                'reservationId' => $reservationId,
                'paymentId' => $payment->id,
                'transactionId' => $transaction->id,
            ];
        });
    }

    /**
     * The Payment a brand-new hold attempt should use, transitioned into
     * HOLD_REQUESTED. Handles the one-Payment-per-Reservation race (§26).
     */
    private function resolvePaymentForNewHold(Reservation $reservation, string $amountValue, ?string $currencyCode): Payment
    {
        $payment = $this->payments->findByReservationForUpdate($reservation->id);

        if ($payment !== null) {
            if (! in_array($payment->status, self::HOLD_STARTABLE_PAYMENT_STATUSES, true)) {
                throw new PaymentAlreadyInitiatedException($payment->status);
            }

            PaymentStateMachine::assertCanTransition($payment->status, Payment::STATUS_HOLD_REQUESTED);

            return $this->payments->update($payment, [
                'status' => Payment::STATUS_HOLD_REQUESTED,
                'amount' => $amountValue,
                'currency' => $currencyCode,
            ]);
        }

        try {
            return $this->payments->create([
                'reservation_id' => $reservation->id,
                // Hotel is ALWAYS derived from the Reservation, never a
                // client value (§7).
                'hotel_id' => $reservation->hotel_id,
                'status' => Payment::STATUS_HOLD_REQUESTED,
                'amount' => $amountValue,
                'currency' => $currencyCode,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another initiation created the Payment between our read and
            // our insert. UNIQUE(reservation_id) is the authoritative guard
            // (§26) — re-read and reject as already-initiated rather than
            // creating a duplicate row or a duplicate provider call.
            $winner = $this->payments->findByReservationForUpdate($reservation->id);

            throw new PaymentAlreadyInitiatedException($winner?->status ?? Payment::STATUS_HOLD_REQUESTED);
        }
    }

    /**
     * The reusable "apply a provider hold result" capability (Phase 5C
     * Step C; Phase 5E webhook processing).
     *
     * Locks are acquired in the approved order Reservation -> Payment ->
     * PaymentTransaction (§12, §25); every record is re-read, never trusted
     * from the caller. It is fully idempotent: a result for a transaction
     * that is no longer `pending` is a no-op that returns the current
     * Payment — a duplicate provider result or a duplicate webhook never
     * applies a transition twice and never throws an invalid transition.
     *
     * The caller passes identifiers + a GatewayResult; it must NOT hold any
     * of the three row locks already (this method acquires them in order),
     * and it may run inside an outer transaction (the webhook boundary
     * does) — the inner DB::transaction then behaves as a savepoint.
     *
     * No provider call happens here.
     */
    /**
     * Release the reservation's deposit hold — the full refund of a free
     * cancellation. The deposit is an authorization until checkout, so
     * releasing it returns the whole amount to the guest.
     *
     * Returns null when there is nothing to release (no payment, or a hold
     * that is not active — a pending one reconciles on its late result). A provider failure leaves the payment as it
     * was and throws — never a faked refund.
     *
     * @throws PaymentRefundFailedException
     */
    public function releaseHold(Reservation $reservation, ?User $actor = null): ?Payment
    {
        $provider = (string) config('payment.provider');

        $open = DB::transaction(function () use ($reservation, $actor, $provider) {
            $payment = $this->payments->findByReservationForUpdate($reservation->id);

            // Only an ACTIVE hold holds money; a still-pending hold follows the
            // existing late-result reconciliation path instead.
            if ($payment === null || $payment->status !== Payment::STATUS_HOLD_ACTIVE) {
                return null;
            }

            $hold = $this->transactions->latestSucceededOfType($payment->id, PaymentTransaction::TYPE_HOLD);

            $transaction = $this->transactions->create([
                'payment_id' => $payment->id,
                'type' => PaymentTransaction::TYPE_CANCEL_HOLD,
                'status' => PaymentTransaction::STATUS_PENDING,
                'amount' => $payment->amount,
                'idempotency_key' => (string) Str::uuid(),
                'provider' => $provider,
                'provider_reference' => null,
                'requested_by_user_id' => $actor?->id,
                'metadata' => null,
            ]);

            $this->auditLogger->record($actor, 'payment.hold_release_requested', $payment,
                after: $this->auditSnapshot($payment, $transaction), hotelId: $payment->hotel_id);

            return ['payment' => $payment, 'transaction' => $transaction, 'reference' => $hold?->provider_reference ?? $transaction->idempotency_key];
        });

        if ($open === null) {
            return null;
        }

        // Provider call outside any DB transaction (§3, §11).
        $result = $this->gateway->cancelHold(new GatewayOperationRequest(providerReference: $open['reference']));

        return DB::transaction(function () use ($open, $result, $actor) {
            $payment = $this->payments->findForUpdate($open['payment']->id);
            $transaction = $this->transactions->findForUpdate($open['transaction']->id);

            if ($result->status !== GatewayResultStatus::Succeeded) {
                $this->transactions->update($transaction, [
                    'status' => PaymentTransaction::STATUS_FAILED,
                    'provider_reference' => $result->providerReference,
                    'metadata' => $this->mergeResultMetadata($transaction, $result),
                ]);
                $this->auditLogger->record($actor, 'payment.hold_release_failed', $payment,
                    after: $this->auditSnapshot($payment, $transaction->refresh()), hotelId: $payment->hotel_id);

                throw new PaymentRefundFailedException($result->providerCode);
            }

            $transaction = $this->transactions->update($transaction, [
                'status' => PaymentTransaction::STATUS_SUCCEEDED,
                'provider_reference' => $result->providerReference,
                'metadata' => $this->mergeResultMetadata($transaction, $result),
            ]);

            $before = $this->auditSnapshot($payment, $transaction);
            PaymentStateMachine::assertCanTransition($payment->status, Payment::STATUS_CANCELLED);
            $payment = $this->payments->update($payment, ['status' => Payment::STATUS_CANCELLED]);

            $this->auditLogger->record($actor, 'payment.hold_released', $payment,
                before: $before, after: $this->auditSnapshot($payment, $transaction), hotelId: $payment->hotel_id);

            return $payment;
        });
    }

    public function applyHoldResult(
        int $reservationId,
        int $paymentId,
        int $transactionId,
        GatewayResult $result,
        ?User $actor = null,
    ): Payment {
        return DB::transaction(function () use ($reservationId, $paymentId, $transactionId, $result, $actor) {
            $reservation = $this->reservations->findForUpdate($reservationId);
            $payment = $this->payments->findForUpdate($paymentId);
            $transaction = $this->transactions->findForUpdate($transactionId);

            if ($payment === null || $transaction === null) {
                throw (new ModelNotFoundException)->setModel(Payment::class, [$paymentId]);
            }

            // Idempotent Step C: a duplicate result (or a later
            // reconciliation pass) finds the attempt already resolved.
            if ($transaction->status !== PaymentTransaction::STATUS_PENDING) {
                return $payment;
            }

            return match ($result->status) {
                GatewayResultStatus::Pending => $this->applyPending($payment, $transaction, $result, $reservation, $actor),
                GatewayResultStatus::Succeeded => $this->applySucceeded($payment, $transaction, $result, $reservation, $actor),
                GatewayResultStatus::Failed => $this->applyTerminalFailure(
                    $payment, $transaction, $result, $reservation, $actor,
                    PaymentTransaction::STATUS_FAILED, Payment::STATUS_HOLD_FAILED, 'payment.hold_failed',
                ),
                GatewayResultStatus::Cancelled => $this->applyTerminalFailure(
                    $payment, $transaction, $result, $reservation, $actor,
                    PaymentTransaction::STATUS_CANCELLED, Payment::STATUS_CANCELLED, 'payment.hold_cancelled',
                ),
                GatewayResultStatus::Expired => $this->applyTerminalFailure(
                    $payment, $transaction, $result, $reservation, $actor,
                    PaymentTransaction::STATUS_EXPIRED, Payment::STATUS_EXPIRED, 'payment.hold_expired',
                ),
            };
        });
    }

    /**
     * Provider PENDING (§17): no status transition anywhere — the Payment
     * State Machine has no self-edge and none is faked. Only the provider
     * reference is retained so the webhook phase can correlate later.
     */
    private function applyPending(
        Payment $payment,
        PaymentTransaction $transaction,
        GatewayResult $result,
        ?Reservation $reservation,
        ?User $actor,
    ): Payment {
        $this->transactions->update($transaction, [
            'provider_reference' => $result->providerReference,
            'metadata' => $this->mergeResultMetadata($transaction, $result),
        ]);

        $this->auditLogger->record(
            $actor,
            'payment.hold_pending',
            $payment,
            after: $this->auditSnapshot($payment, $transaction->refresh()),
            hotelId: $payment->hotel_id,
        );

        return $payment;
    }

    /**
     * Provider SUCCEEDED (§13, §18). Transaction -> SUCCEEDED, Payment ->
     * HOLD_ACTIVE. Reservation PENDING -> DEPOSIT_HELD via ReservationService;
     * if the Reservation is no longer PENDING (late success after
     * cancellation) the Payment still becomes HOLD_ACTIVE and the
     * inconsistency is recorded for reconciliation — never auto-refunded,
     * never reactivated.
     */
    private function applySucceeded(
        Payment $payment,
        PaymentTransaction $transaction,
        GatewayResult $result,
        ?Reservation $reservation,
        ?User $actor,
    ): Payment {
        $transaction = $this->transactions->update($transaction, [
            'status' => PaymentTransaction::STATUS_SUCCEEDED,
            'provider_reference' => $result->providerReference,
            'metadata' => $this->mergeResultMetadata($transaction, $result),
        ]);

        $before = $this->auditSnapshot($payment, $transaction);

        PaymentStateMachine::assertCanTransition($payment->status, Payment::STATUS_HOLD_ACTIVE);
        $payment = $this->payments->update($payment, ['status' => Payment::STATUS_HOLD_ACTIVE]);

        if ($reservation !== null && $reservation->status === Reservation::STATUS_PENDING) {
            $this->reservationService->transitionTo($reservation, Reservation::STATUS_DEPOSIT_HELD, $actor);

            $this->auditLogger->record(
                $actor,
                'payment.hold_succeeded',
                $payment,
                before: $before,
                after: $this->auditSnapshot($payment, $transaction) + ['reservation_status' => Reservation::STATUS_DEPOSIT_HELD],
                hotelId: $payment->hotel_id,
            );

            return $payment;
        }

        // Late success after the Reservation already closed (§18).
        $this->auditLogger->record(
            $actor,
            'payment.hold_succeeded_after_reservation_closed',
            $payment,
            before: $before,
            after: $this->auditSnapshot($payment, $transaction) + [
                'reservation_status' => $reservation?->status,
                'requires_reconciliation' => true,
            ],
            hotelId: $payment->hotel_id,
        );

        return $payment;
    }

    /**
     * Provider FAILED / CANCELLED / EXPIRED (§14, §15, §16). Transaction and
     * Payment move to their terminal failure statuses; a still-PENDING
     * Reservation is cancelled through ReservationService. A Reservation
     * that already left PENDING is left exactly as it is (§19).
     */
    private function applyTerminalFailure(
        Payment $payment,
        PaymentTransaction $transaction,
        GatewayResult $result,
        ?Reservation $reservation,
        ?User $actor,
        string $transactionStatus,
        string $paymentStatus,
        string $auditAction,
    ): Payment {
        $transaction = $this->transactions->update($transaction, [
            'status' => $transactionStatus,
            'provider_reference' => $result->providerReference,
            'metadata' => $this->mergeResultMetadata($transaction, $result),
        ]);

        $before = $this->auditSnapshot($payment, $transaction);

        PaymentStateMachine::assertCanTransition($payment->status, $paymentStatus);
        $payment = $this->payments->update($payment, ['status' => $paymentStatus]);

        $reservationOutcome = $reservation?->status;

        if ($reservation !== null && $reservation->status === Reservation::STATUS_PENDING) {
            $this->reservationService->transitionTo($reservation, Reservation::STATUS_CANCELLED, $actor);
            $reservationOutcome = Reservation::STATUS_CANCELLED;
        }

        $this->auditLogger->record(
            $actor,
            $auditAction,
            $payment,
            before: $before,
            after: $this->auditSnapshot($payment, $transaction) + ['reservation_status' => $reservationOutcome],
            hotelId: $payment->hotel_id,
        );

        return $payment;
    }

    /**
     * A replayed idempotency key must describe the exact same logical
     * operation (§9, §27): same Reservation's Payment, same provider, same
     * "hold" operation. Anything else is a conflict, not a reuse.
     */
    private function assertIdempotentMatch(PaymentTransaction $existing, Reservation $reservation, string $provider): void
    {
        if ($existing->type !== PaymentTransaction::TYPE_HOLD) {
            throw new IdempotencyKeyConflictException('different operation type');
        }

        if ($existing->provider !== $provider) {
            throw new IdempotencyKeyConflictException('different provider');
        }

        $payment = $this->payments->find($existing->payment_id);

        if ($payment === null || $payment->reservation_id !== $reservation->id) {
            throw new IdempotencyKeyConflictException('different reservation');
        }
    }

    /**
     * @return array<string, string>
     */
    private function initialTransactionMetadata(?SimulationDirective $simulationDirective): array
    {
        return array_filter([
            'simulation_directive' => $simulationDirective?->value,
        ], fn ($value) => $value !== null);
    }

    /**
     * Fold the safe, non-sensitive parts of a provider result into the
     * transaction metadata. The gateway contract already guarantees no
     * secret / card data / raw payload reaches here (Phase 5B).
     *
     * @return array<string, mixed>
     */
    private function mergeResultMetadata(PaymentTransaction $transaction, GatewayResult $result): array
    {
        return array_merge($transaction->metadata ?? [], [
            'provider_code' => $result->providerCode,
            'provider_message' => $result->message,
        ]);
    }

    /**
     * A safe, flat snapshot for the audit trail — business status fields
     * only. Never an amount the caller did not give us, never a secret,
     * card number, PIN, or raw provider payload (§24).
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(Payment $payment, ?PaymentTransaction $transaction = null): array
    {
        return array_filter([
            'payment_status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'transaction_status' => $transaction?->status,
            'transaction_type' => $transaction?->type,
            'provider' => $transaction?->provider,
            'provider_reference' => $transaction?->provider_reference,
        ], fn ($value) => $value !== null);
    }

    /**
     * Normalize an amount to a canonical DECIMAL(12,2) string without float
     * arithmetic on the persisted value (§29). Rejects non-positive values
     * and any precision finer than two decimal places rather than rounding.
     */
    private function normalizeAmount(int|float|string $amount): string
    {
        if (is_float($amount)) {
            // Expose sub-cent precision instead of silently truncating it.
            $amount = rtrim(rtrim(sprintf('%.4f', $amount), '0'), '.');

            if ($amount === '' || $amount === '-') {
                $amount = '0';
            }
        }

        $amount = trim((string) $amount);

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount)) {
            throw new InvalidPaymentAmountException('not a positive value within DECIMAL(12,2)');
        }

        if (! preg_match('/[1-9]/', $amount)) {
            throw new InvalidPaymentAmountException('must be greater than zero');
        }

        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * The staff payments ledger for a hotel — a pure read, no workflow
     * decision. `status` is an optional exact-match filter over
     * Payment::STATUSES.
     *
     * @param  array{status?: string|null}  $filters
     */
    public function listForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->payments->paginateForHotel($hotelId, $filters, $perPage);
    }

    /**
     * Currency resolution order (§30): explicit argument, then
     * config('payment.currency'), then null. No business default is ever
     * invented. A non-null value must be a well-formed ISO-4217 alpha code.
     */
    private function resolveCurrency(?string $currency): ?string
    {
        $resolved = $currency ?? config('payment.currency');

        if ($resolved === null || $resolved === '') {
            return null;
        }

        $resolved = strtoupper(trim($resolved));

        if (! preg_match('/^[A-Z]{3}$/', $resolved)) {
            throw new InvalidPaymentCurrencyException($resolved);
        }

        return $resolved;
    }
}
