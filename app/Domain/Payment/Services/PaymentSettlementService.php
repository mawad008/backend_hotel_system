<?php

namespace App\Domain\Payment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Payment\Exceptions\IdempotencyKeyConflictException;
use App\Domain\Payment\Exceptions\PaymentSettlementNotAllowedException;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 9C — the final-settlement payment workflow (Phase 0 §9/§12,
 * R26-R31: "single settlement at checkout"). A sibling of
 * PaymentWorkflowService (which owns the deposit hold) — it reuses the same
 * repositories, the same PaymentGatewayInterface boundary, and the same
 * PaymentStateMachine. It never redefines a Payment status or invents an
 * enum value.
 *
 * ══ Absolute architectural rule ══
 * The gateway (PaymentGatewayInterface::settle) is NEVER called while a DB
 * transaction is open. Three explicit stages, mirroring Phase 5C:
 *
 *   STEP A  (DB txn)  lock Reservation -> Payment, guard the state, advance
 *                     the Payment to FINAL_SETTLEMENT_REQUESTED through the
 *                     approved state machine, create the settlement
 *                     PaymentTransaction (pending), audit, COMMIT.
 *   STEP B  (no txn)  PaymentGatewayInterface::settle(...).
 *   STEP C  (DB txn)  re-lock Reservation -> Payment -> PaymentTransaction,
 *                     apply the normalized result, audit, COMMIT.
 *
 * ── Technical decision (documented) ──
 * The whole *outstanding* balance is collected with ONE provider settle()
 * call. When the deposit hold was never captured (capture-at-check-in was
 * not implemented in Phase 7), the Payment is walked
 * HOLD_ACTIVE -> CAPTURE_REQUESTED -> CAPTURED -> FINAL_SETTLEMENT_REQUESTED
 * inside STEP A — every hop guarded by PaymentStateMachine, no fabricated
 * enum value. A production gateway adapter would issue the provider capture
 * during that leg; the dummy models the collection as a single settle.
 *
 * ── Payment accounting (Phase 9 review fix) ──
 * `payments.amount` is NEVER overwritten — it keeps its Phase 5 meaning (the
 * deposit requested at booking). The settlement transaction records
 * `amount` = the outstanding actually collected. The folio's
 * `payments_total` is the SUM of the payment's succeeded capture + settlement
 * transaction amounts, so a prior capture and this settlement are counted
 * separately and the history is preserved.
 */
class PaymentSettlementService
{
    /**
     * Payment statuses a final settlement may be (re)started from.
     * SETTLEMENT_FAILED is included — the state machine allows a retry
     * (SETTLEMENT_FAILED -> FINAL_SETTLEMENT_REQUESTED).
     *
     * @var list<string>
     */
    private const SETTLEABLE_STATUSES = [
        Payment::STATUS_HOLD_ACTIVE,
        Payment::STATUS_CAPTURE_REQUESTED,
        Payment::STATUS_CAPTURED,
        Payment::STATUS_FINAL_SETTLEMENT_REQUESTED,
        Payment::STATUS_SETTLEMENT_FAILED,
    ];

    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentRepositoryInterface $payments,
        private readonly PaymentTransactionRepositoryInterface $transactions,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Collect the outstanding balance for a reservation's payment. The
     * settlement transaction records `amount` = $settlementAmount (the
     * outstanding actually collected). `payments.amount` is never touched.
     *
     * @param  string  $settlementAmount  the server-calculated outstanding to collect (> 0), a DECIMAL(12,2) string
     *
     * Returns the resolved Payment. A declined / pending provider outcome is
     * a returned Payment state, not an exception.
     *
     * MUST NOT be called from inside an open DB transaction.
     *
     * @throws PaymentSettlementNotAllowedException if the payment cannot be settled
     * @throws IdempotencyKeyConflictException if the key was used for a different operation
     */
    public function settle(
        Reservation $reservation,
        string $settlementAmount,
        string $currency,
        ?string $idempotencyKey = null,
        ?SimulationDirective $directive = null,
        ?User $actor = null,
    ): Payment {
        $key = $idempotencyKey ?? (string) Str::uuid();
        $provider = (string) config('payment.provider');

        $stepA = $this->openSettlementAttempt(
            $reservation->id, $settlementAmount, $currency, $key, $provider, $directive, $actor,
        );

        if ($stepA['action'] === 'replay') {
            $payment = $this->payments->find($stepA['paymentId']);

            if ($payment === null) {
                throw (new ModelNotFoundException)->setModel(Payment::class, [$stepA['paymentId']]);
            }

            return $payment;
        }

        // ── STEP B ── provider call, OUTSIDE any DB transaction. `settle`
        // starts a fresh collection; `verify` re-checks a settlement that a
        // prior attempt left pending (the recovery path — no webhook exists
        // for settlement yet).
        $result = $stepA['action'] === 'verify'
            ? $this->gateway->verify(new GatewayOperationRequest(
                providerReference: $stepA['seedReference'],
                directive: $directive,
            ))
            : $this->gateway->settle(new GatewayOperationRequest(
                providerReference: $stepA['seedReference'],
                directive: $directive,
                metadata: ['amount' => $settlementAmount, 'currency' => $currency],
            ));

        // ── STEP C ──
        return $this->applySettlementResult(
            $stepA['reservationId'], $stepA['paymentId'], $stepA['transactionId'], $result, $actor,
        );
    }

    /**
     * STEP A — no provider call. One transaction that fully commits or fully
     * rolls back. `action` is 'replay' (return the payment as-is), 'settle'
     * (fresh provider settlement) or 'verify' (re-check a pending one).
     *
     * @return array{action: 'replay'|'settle'|'verify', reservationId: int, paymentId: int, transactionId: int|null, seedReference: string}
     */
    private function openSettlementAttempt(
        int $reservationId,
        string $settlementAmount,
        string $currency,
        string $key,
        string $provider,
        ?SimulationDirective $directive,
        ?User $actor,
    ): array {
        return DB::transaction(function () use (
            $reservationId, $settlementAmount, $currency, $key, $provider, $actor,
        ) {
            $reservation = $this->reservations->findForUpdate($reservationId);

            if (! $reservation) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservationId]);
            }

            $payment = $this->payments->findByReservationForUpdate($reservationId);

            if ($payment === null) {
                throw new PaymentSettlementNotAllowedException('none');
            }

            $seed = $payment->provider_customer_ref ?: $key;

            // Idempotent: the key already carries a settlement attempt.
            $existing = $this->transactions->findByIdempotencyKey($key);

            if ($existing !== null) {
                $this->assertIdempotentMatch($existing, $payment, $provider);

                // A still-pending attempt for this key -> re-check it with the
                // provider rather than replay a stale "pending".
                $action = $existing->status === PaymentTransaction::STATUS_PENDING ? 'verify' : 'replay';

                return $this->plan($action, $reservationId, $payment->id, $existing->id, $seed);
            }

            // Already settled — nothing to do.
            if ($payment->status === Payment::STATUS_SETTLED) {
                return $this->plan('replay', $reservationId, $payment->id, null, $seed);
            }

            // A prior attempt left the payment mid-settlement with a pending
            // transaction (any key) — re-check that one, never start a second.
            if ($payment->status === Payment::STATUS_FINAL_SETTLEMENT_REQUESTED) {
                $pending = $this->pendingSettlementTransactionFor($payment);

                if ($pending !== null) {
                    return $this->plan('verify', $reservationId, $payment->id, $pending->id, $seed);
                }
            }

            if (! in_array($payment->status, self::SETTLEABLE_STATUSES, true)) {
                throw new PaymentSettlementNotAllowedException($payment->status);
            }

            $before = $this->auditSnapshot($payment);
            $payment = $this->advanceToSettlementRequested($payment);

            try {
                $transaction = $this->transactions->create([
                    'payment_id' => $payment->id,
                    'type' => PaymentTransaction::TYPE_SETTLEMENT,
                    'status' => PaymentTransaction::STATUS_PENDING,
                    // The outstanding balance being collected — this is what
                    // counts toward payments_total once the row succeeds.
                    'amount' => $settlementAmount,
                    'idempotency_key' => $key,
                    'provider' => $provider,
                    'provider_reference' => null,
                    'requested_by_user_id' => $actor?->id,
                    'metadata' => ['currency' => $currency],
                ]);
            } catch (UniqueConstraintViolationException) {
                $winner = $this->transactions->findByIdempotencyKey($key);

                if ($winner === null) {
                    throw new PaymentSettlementNotAllowedException($payment->status);
                }

                return $this->plan('verify', $reservationId, $payment->id, $winner->id, $seed);
            }

            $this->auditLogger->record(
                $actor,
                'payment.final_settlement_requested',
                $payment,
                before: $before,
                after: $this->auditSnapshot($payment, $transaction),
                hotelId: $payment->hotel_id,
            );

            return $this->plan('settle', $reservationId, $payment->id, $transaction->id, $seed);
        });
    }

    /**
     * STEP C — the reusable "apply a provider settlement result" capability
     * (Phase 9C step C; a future reconciliation/webhook pass). Fully
     * idempotent: a result for a transaction that is no longer `pending` is a
     * no-op that returns the current Payment. No provider call happens here.
     */
    public function applySettlementResult(
        int $reservationId,
        int $paymentId,
        int $transactionId,
        GatewayResult $result,
        ?User $actor = null,
    ): Payment {
        return DB::transaction(function () use ($reservationId, $paymentId, $transactionId, $result, $actor) {
            $this->reservations->findForUpdate($reservationId);
            $payment = $this->payments->findForUpdate($paymentId);
            $transaction = $this->transactions->findForUpdate($transactionId);

            if ($payment === null || $transaction === null) {
                throw (new ModelNotFoundException)->setModel(Payment::class, [$paymentId]);
            }

            if ($transaction->status !== PaymentTransaction::STATUS_PENDING) {
                return $payment;
            }

            return match ($result->status) {
                GatewayResultStatus::Succeeded => $this->applySettled($payment, $transaction, $result, $actor),
                GatewayResultStatus::Pending => $this->applyPending($payment, $transaction, $result, $actor),
                GatewayResultStatus::Failed => $this->applyFailed($payment, $transaction, $result, $actor, PaymentTransaction::STATUS_FAILED),
                GatewayResultStatus::Cancelled => $this->applyFailed($payment, $transaction, $result, $actor, PaymentTransaction::STATUS_CANCELLED),
                GatewayResultStatus::Expired => $this->applyFailed($payment, $transaction, $result, $actor, PaymentTransaction::STATUS_EXPIRED),
            };
        });
    }

    private function applySettled(
        Payment $payment,
        PaymentTransaction $transaction,
        GatewayResult $result,
        ?User $actor,
    ): Payment {
        $this->transactions->update($transaction, [
            'status' => PaymentTransaction::STATUS_SUCCEEDED,
            'provider_reference' => $result->providerReference,
            'metadata' => $this->mergeResultMetadata($transaction, $result),
        ]);

        $before = $this->auditSnapshot($payment);

        PaymentStateMachine::assertCanTransition($payment->status, Payment::STATUS_SETTLED);
        // `payments.amount` is NOT touched — it keeps its Phase 5 meaning
        // (the deposit requested at booking). The succeeded settlement
        // transaction's `amount` is what reconciles payments_total to the
        // folio.
        $payment = $this->payments->update($payment, ['status' => Payment::STATUS_SETTLED]);

        $this->auditLogger->record(
            $actor,
            'payment.settled',
            $payment,
            before: $before,
            after: $this->auditSnapshot($payment, $transaction->refresh()),
            hotelId: $payment->hotel_id,
        );

        return $payment;
    }

    private function applyPending(
        Payment $payment,
        PaymentTransaction $transaction,
        GatewayResult $result,
        ?User $actor,
    ): Payment {
        // No status transition — the state machine has no self-edge and none
        // is faked. Keep the reference so a later pass can correlate.
        $this->transactions->update($transaction, [
            'provider_reference' => $result->providerReference,
            'metadata' => $this->mergeResultMetadata($transaction, $result),
        ]);

        $this->auditLogger->record(
            $actor,
            'payment.settlement_pending',
            $payment,
            after: $this->auditSnapshot($payment, $transaction->refresh()),
            hotelId: $payment->hotel_id,
        );

        return $payment;
    }

    private function applyFailed(
        Payment $payment,
        PaymentTransaction $transaction,
        GatewayResult $result,
        ?User $actor,
        string $transactionStatus,
    ): Payment {
        $transaction = $this->transactions->update($transaction, [
            'status' => $transactionStatus,
            'provider_reference' => $result->providerReference,
            'metadata' => $this->mergeResultMetadata($transaction, $result),
        ]);

        $before = $this->auditSnapshot($payment);

        // FINAL_SETTLEMENT_REQUESTED only transitions to SETTLED or
        // SETTLEMENT_FAILED — a cancelled/expired provider outcome maps to
        // SETTLEMENT_FAILED for the Payment (the transaction records the
        // exact outcome). The reservation stays non-final; retry is allowed.
        PaymentStateMachine::assertCanTransition($payment->status, Payment::STATUS_SETTLEMENT_FAILED);
        $payment = $this->payments->update($payment, ['status' => Payment::STATUS_SETTLEMENT_FAILED]);

        $this->auditLogger->record(
            $actor,
            'payment.settlement_failed',
            $payment,
            before: $before,
            after: $this->auditSnapshot($payment, $transaction) + ['provider_outcome' => $result->status->value],
            hotelId: $payment->hotel_id,
        );

        return $payment;
    }

    /**
     * Walk the Payment to FINAL_SETTLEMENT_REQUESTED through the approved
     * state machine — every hop guarded, no fabricated enum value. From
     * HOLD_ACTIVE this passes through CAPTURE_REQUESTED and CAPTURED within
     * this single transaction (see the class docblock).
     */
    private function advanceToSettlementRequested(Payment $payment): Payment
    {
        $path = match ($payment->status) {
            Payment::STATUS_HOLD_ACTIVE => [
                Payment::STATUS_CAPTURE_REQUESTED,
                Payment::STATUS_CAPTURED,
                Payment::STATUS_FINAL_SETTLEMENT_REQUESTED,
            ],
            Payment::STATUS_CAPTURE_REQUESTED => [
                Payment::STATUS_CAPTURED,
                Payment::STATUS_FINAL_SETTLEMENT_REQUESTED,
            ],
            Payment::STATUS_CAPTURED => [Payment::STATUS_FINAL_SETTLEMENT_REQUESTED],
            Payment::STATUS_SETTLEMENT_FAILED => [Payment::STATUS_FINAL_SETTLEMENT_REQUESTED],
            Payment::STATUS_FINAL_SETTLEMENT_REQUESTED => [],
            default => throw new PaymentSettlementNotAllowedException($payment->status),
        };

        foreach ($path as $next) {
            PaymentStateMachine::assertCanTransition($payment->status, $next);
            $payment = $this->payments->update($payment, ['status' => $next]);
        }

        return $payment;
    }

    private function pendingSettlementTransactionFor(Payment $payment): ?PaymentTransaction
    {
        return $payment->transactions()
            ->where('type', PaymentTransaction::TYPE_SETTLEMENT)
            ->where('status', PaymentTransaction::STATUS_PENDING)
            ->orderByDesc('id')
            ->first();
    }

    private function assertIdempotentMatch(PaymentTransaction $existing, Payment $payment, string $provider): void
    {
        if ($existing->type !== PaymentTransaction::TYPE_SETTLEMENT) {
            throw new IdempotencyKeyConflictException('different operation type');
        }

        if ($existing->provider !== $provider) {
            throw new IdempotencyKeyConflictException('different provider');
        }

        if ($existing->payment_id !== $payment->id) {
            throw new IdempotencyKeyConflictException('different reservation');
        }
    }

    /**
     * @param  'replay'|'settle'|'verify'  $action
     * @return array{action: 'replay'|'settle'|'verify', reservationId: int, paymentId: int, transactionId: int|null, seedReference: string}
     */
    private function plan(string $action, int $reservationId, int $paymentId, ?int $transactionId, string $seed): array
    {
        return [
            'action' => $action,
            'reservationId' => $reservationId,
            'paymentId' => $paymentId,
            'transactionId' => $transactionId,
            'seedReference' => $seed,
        ];
    }

    /**
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
     * only. Never a secret, card number, PIN, or raw provider payload.
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(Payment $payment, ?PaymentTransaction $transaction = null): array
    {
        return array_filter([
            'payment_status' => $payment->status,
            'deposit_amount' => $payment->amount,
            'currency' => $payment->currency,
            'transaction_status' => $transaction?->status,
            'transaction_type' => $transaction?->type,
            'transaction_amount' => $transaction?->amount,
            'provider' => $transaction?->provider,
            'provider_reference' => $transaction?->provider_reference,
        ], fn ($value) => $value !== null);
    }
}
