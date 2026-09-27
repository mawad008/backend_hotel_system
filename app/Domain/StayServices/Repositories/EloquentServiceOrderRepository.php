<?php

namespace App\Domain\StayServices\Repositories;

use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Repositories\Contracts\ServiceOrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentServiceOrderRepository implements ServiceOrderRepositoryInterface
{
    public function paginateForReservation(int $reservationId, int $perPage = 15): LengthAwarePaginator
    {
        return ServiceOrder::query()
            ->where('reservation_id', $reservationId)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function allForReservation(int $reservationId): Collection
    {
        return ServiceOrder::query()
            ->where('reservation_id', $reservationId)
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id): ?ServiceOrder
    {
        return ServiceOrder::query()->find($id);
    }

    public function statsForHotel(int $hotelId, string $from, string $to): array
    {
        $byStatus = ServiceOrder::query()
            ->where('hotel_id', $hotelId)
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        $revenue = ServiceOrder::query()
            ->where('hotel_id', $hotelId)
            ->whereIn('status', [ServiceOrder::STATUS_CONFIRMED, ServiceOrder::STATUS_FULFILLED])
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->groupBy('currency_snapshot')
            ->selectRaw('currency_snapshot as currency, COALESCE(SUM(total_amount), 0) as amount')
            ->get()
            ->map(fn ($row) => ['currency' => (string) $row->currency, 'amount' => bcadd((string) $row->amount, '0', 2)])
            ->values()
            ->all();

        return ['by_status' => $byStatus, 'revenue' => $revenue];
    }

    public function findForUpdate(int $id): ?ServiceOrder
    {
        return ServiceOrder::query()->lockForUpdate()->find($id);
    }

    public function create(array $data): ServiceOrder
    {
        return ServiceOrder::create($data)->refresh();
    }

    public function update(ServiceOrder $order, array $data): ServiceOrder
    {
        $order->update($data);

        return $order->refresh();
    }
}
