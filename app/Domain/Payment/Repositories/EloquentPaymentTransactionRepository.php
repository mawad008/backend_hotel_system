<?php

namespace App\Domain\Payment\Repositories;

use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Repositories\Contracts\PaymentTransactionRepositoryInterface;

class EloquentPaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    public function find(int $id): ?PaymentTransaction
    {
        return PaymentTransaction::query()->find($id);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?PaymentTransaction
    {
        return PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    public function findForUpdate(int $id): ?PaymentTransaction
    {
        return PaymentTransaction::query()->lockForUpdate()->find($id);
    }

    public function findByProviderReference(string $provider, string $providerReference): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('provider', $provider)
            ->where('provider_reference', $providerReference)
            ->first();
    }

    public function latestSucceededOfType(int $paymentId, string $type): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('payment_id', $paymentId)
            ->where('type', $type)
            ->where('status', PaymentTransaction::STATUS_SUCCEEDED)
            ->latest('id')
            ->first();
    }

    public function sumCollectedForPayment(int $paymentId): string
    {
        // The database computes the SUM over the DECIMAL column — no float
        // arithmetic. bcadd normalizes the (possibly null) result to 2 places.
        $sum = PaymentTransaction::query()
            ->where('payment_id', $paymentId)
            ->whereIn('type', PaymentTransaction::COLLECTED_TYPES)
            ->where('status', PaymentTransaction::STATUS_SUCCEEDED)
            ->selectRaw('COALESCE(SUM(amount), 0) as aggregate')
            ->value('aggregate');

        return bcadd((string) $sum, '0', 2);
    }

    public function collectedSumsGroupedByReservationForHotel(int $hotelId): array
    {
        return PaymentTransaction::query()
            ->join('payments', 'payments.id', '=', 'payment_transactions.payment_id')
            ->where('payments.hotel_id', $hotelId)
            ->whereIn('payment_transactions.type', PaymentTransaction::COLLECTED_TYPES)
            ->where('payment_transactions.status', PaymentTransaction::STATUS_SUCCEEDED)
            ->groupBy('payments.reservation_id')
            ->selectRaw('payments.reservation_id as reservation_id, COALESCE(SUM(payment_transactions.amount), 0) as aggregate')
            ->pluck('aggregate', 'reservation_id')
            ->map(fn ($v) => bcadd((string) $v, '0', 2))
            ->all();
    }

    public function create(array $data): PaymentTransaction
    {
        return PaymentTransaction::create($data)->refresh();
    }

    public function update(PaymentTransaction $transaction, array $data): PaymentTransaction
    {
        $transaction->update($data);

        return $transaction->refresh();
    }
}
