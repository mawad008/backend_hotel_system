<?php

namespace App\Domain\Checkout\Repositories\Contracts;

use App\Domain\Checkout\Models\Checkout;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CheckoutRepositoryInterface
{
    /**
     * Every Checkout belonging to a hotel (the staff settlements ledger),
     * newest first. `status` is an optional exact-match filter over
     * Checkout::STATUSES.
     *
     * @param  array{status?: string|null}  $filters
     */
    public function paginateForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator;

    public function find(int $id): ?Checkout;

    /**
     * The single checkout for a reservation (approved 1:1), or null.
     */
    public function findByReservation(int $reservationId): ?Checkout;

    /**
     * `findByReservation()` under a `SELECT ... FOR UPDATE` row lock — so two
     * concurrent checkouts cannot both create or advance the record. Call
     * only from within an active DB::transaction().
     */
    public function findByReservationForUpdate(int $reservationId): ?Checkout;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Checkout;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Checkout $checkout, array $data): Checkout;
}
