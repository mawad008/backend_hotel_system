<?php

namespace App\Domain\Payment\Repositories\Contracts;

use App\Domain\Payment\Models\PaymentTransaction;

interface PaymentTransactionRepositoryInterface
{
    public function find(int $id): ?PaymentTransaction;

    /**
     * Lookup by the globally-unique idempotency key — the basis of the
     * approved DB-backed idempotency design (Phase 5 plan v2 §12). Returns
     * null when the key has not been used.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?PaymentTransaction;

    /**
     * Lookup by the provider transaction reference within a provider.
     * `(provider, provider_reference)` is UNIQUE (Phase 5A), so this
     * returns at most one row. Used by the Phase 5E webhook boundary to
     * match an inbound event to its pending hold attempt. A plain,
     * non-locking read — the caller (PaymentWebhookService) never locks the
     * transaction itself; PaymentWorkflowService::applyHoldResult owns the
     * Reservation -> Payment -> PaymentTransaction lock order.
     */
    public function findByProviderReference(string $provider, string $providerReference): ?PaymentTransaction;

    /**
     * The decimal-string SUM of `amount` over the payment's succeeded
     * money-collecting transactions (`capture` + `settlement`). This is the
     * folio's `payments_total` — the payment history is the source of truth,
     * never `payments.amount` (Phase 9 review fix). Returns a canonical
     * "0.00" when there are none.
     */
    public function sumCollectedForPayment(int $paymentId): string;

    /** The latest succeeded transaction of $type for a payment, or null. */
    public function latestSucceededOfType(int $paymentId, string $type): ?PaymentTransaction;

    /**
     * The same "succeeded capture + settlement" sum as
     * sumCollectedForPayment(), computed for every reservation of
     * $hotelId at once (joined through `payments`) — the folio ledger's
     * efficient outstanding-balance data source.
     *
     * @return array<int, string> reservation_id => decimal-string sum
     */
    public function collectedSumsGroupedByReservationForHotel(int $hotelId): array;

    /**
     * `find()` under a `SELECT ... FOR UPDATE` row lock — the lock the
     * Phase 5C payment workflow acquires in Step C (third in the approved
     * Reservation -> Payment -> PaymentTransaction order). Must be called
     * only from within an active DB::transaction().
     */
    public function findForUpdate(int $id): ?PaymentTransaction;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PaymentTransaction;

    /**
     * Persist $data onto $transaction and return the refreshed model. Pure
     * persistence — transaction-status validity is decided by the caller
     * (PaymentWorkflowService), never here.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PaymentTransaction $transaction, array $data): PaymentTransaction;
}
