<?php

namespace App\Domain\Checkout\Repositories;

use App\Domain\Checkout\Models\Invoice;
use App\Domain\Checkout\Models\InvoiceItem;
use App\Domain\Checkout\Repositories\Contracts\InvoiceRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentInvoiceRepository implements InvoiceRepositoryInterface
{
    public function paginateForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Invoice::query()
            ->where('hotel_id', $hotelId)
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $id): ?Invoice
    {
        return Invoice::query()->with('items')->find($id);
    }

    public function findByReservation(int $reservationId): ?Invoice
    {
        return Invoice::query()->with('items')->where('reservation_id', $reservationId)->first();
    }

    public function findByReservationForUpdate(int $reservationId): ?Invoice
    {
        return Invoice::query()
            ->where('reservation_id', $reservationId)
            ->lockForUpdate()
            ->first();
    }

    public function create(array $data): Invoice
    {
        return Invoice::create($data)->refresh();
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        $invoice->update($data);

        return $invoice->refresh()->load('items');
    }

    public function addItem(Invoice $invoice, array $data): InvoiceItem
    {
        return $invoice->items()->create($data)->refresh();
    }
}
