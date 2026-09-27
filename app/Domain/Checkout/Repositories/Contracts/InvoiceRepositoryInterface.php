<?php

namespace App\Domain\Checkout\Repositories\Contracts;

use App\Domain\Checkout\Models\Invoice;
use App\Domain\Checkout\Models\InvoiceItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface InvoiceRepositoryInterface
{
    /**
     * Every issued invoice belonging to a hotel (the staff invoices
     * ledger), newest first. `status` is an optional exact-match filter
     * over Invoice::STATUSES.
     *
     * @param  array{status?: string|null}  $filters
     */
    public function paginateForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator;

    public function find(int $id): ?Invoice;

    /**
     * The single final invoice for a reservation (approved 1:1), or null.
     * `items` is eager-loaded.
     */
    public function findByReservation(int $reservationId): ?Invoice;

    /**
     * `findByReservation()` under a row lock. Call only from within an
     * active DB::transaction().
     */
    public function findByReservationForUpdate(int $reservationId): ?Invoice;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Invoice;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Invoice $invoice, array $data): Invoice;

    /**
     * @param  array<string, mixed>  $data
     */
    public function addItem(Invoice $invoice, array $data): InvoiceItem;
}
