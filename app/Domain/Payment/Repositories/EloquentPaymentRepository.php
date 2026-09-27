<?php

namespace App\Domain\Payment\Repositories;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentPaymentRepository implements PaymentRepositoryInterface
{
    public function paginateForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Payment::query()
            ->where('hotel_id', $hotelId)
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Sums the succeeded money-moving transactions
     * (PaymentTransaction::COLLECTED_TYPES — the same definition the folio's
     * `payments_total` uses), not `payments.amount`: that column is the
     * deposit HOLD amount, while checkout settles the whole folio balance on
     * a separate `settlement` transaction. A transaction's `updated_at` is
     * when it reached SUCCEEDED (it never mutates after that).
     */
    public function sumCapturedForHotelByCurrency(int $hotelId, string $from, string $to): Collection
    {
        return PaymentTransaction::query()
            ->join('payments', 'payments.id', '=', 'payment_transactions.payment_id')
            ->where('payments.hotel_id', $hotelId)
            ->whereIn('payment_transactions.type', PaymentTransaction::COLLECTED_TYPES)
            ->where('payment_transactions.status', PaymentTransaction::STATUS_SUCCEEDED)
            ->whereNotNull('payment_transactions.amount')
            ->whereBetween('payment_transactions.updated_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->selectRaw('COALESCE(payment_transactions.currency, payments.currency) as currency, SUM(payment_transactions.amount) as total')
            ->groupByRaw('COALESCE(payment_transactions.currency, payments.currency)')
            ->get();
    }

    public function countsByStatusForHotel(int $hotelId, string $from, string $to): array
    {
        return Payment::query()
            ->where('hotel_id', $hotelId)
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();
    }

    public function find(int $id): ?Payment
    {
        return Payment::query()->find($id);
    }

    public function findByReservation(int $reservationId): ?Payment
    {
        return Payment::query()->where('reservation_id', $reservationId)->first();
    }

    public function findForUpdate(int $id): ?Payment
    {
        return Payment::query()->lockForUpdate()->find($id);
    }

    public function findByReservationForUpdate(int $reservationId): ?Payment
    {
        return Payment::query()->where('reservation_id', $reservationId)->lockForUpdate()->first();
    }

    public function create(array $data): Payment
    {
        return Payment::create($data)->refresh();
    }

    public function update(Payment $payment, array $data): Payment
    {
        $payment->update($data);

        return $payment->refresh();
    }
}
