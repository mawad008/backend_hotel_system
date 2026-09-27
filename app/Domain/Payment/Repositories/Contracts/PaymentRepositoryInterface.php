<?php

namespace App\Domain\Payment\Repositories\Contracts;

use App\Domain\Payment\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface PaymentRepositoryInterface
{
    /**
     * Every Payment belonging to a hotel (the staff payments ledger),
     * newest first. `status` is an optional exact-match filter over
     * Payment::STATUSES.
     *
     * @param  array{status?: string|null}  $filters
     */
    public function paginateForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Payment counts by status for $hotelId, created within [$from, $to]
     * — the payments report's data source.
     *
     * @return array<string, int>
     */
    public function countsByStatusForHotel(int $hotelId, string $from, string $to): array;

    /**
     * Captured-or-settled money in for $hotelId within [$from, $to],
     * grouped by currency (a deployment may not be single-currency, and
     * amounts are never summed across currencies) — the revenue report's
     * data source. Rows shaped `{currency: string, total: string}`.
     *
     * @return Collection<int, object{currency: string, total: string}>
     */
    public function sumCapturedForHotelByCurrency(int $hotelId, string $from, string $to): Collection;

    /**
     * Plain lookup by id — no authorization decision is made here, that is
     * the Policy's responsibility (added in a later sub-phase).
     */
    public function find(int $id): ?Payment;

    /**
     * The single Payment belonging to a Reservation (approved 1:1
     * relationship), or null if none has been started yet.
     */
    public function findByReservation(int $reservationId): ?Payment;

    /**
     * `find()` under a `SELECT ... FOR UPDATE` row lock. Must be called only
     * from within an active DB::transaction() — the lock the Phase 5C
     * payment workflow relies on for its Step C re-read.
     */
    public function findForUpdate(int $id): ?Payment;

    /**
     * `findByReservation()` under a row lock — used in Phase 5C Step A so a
     * concurrent initiation cannot both discover "no Payment" and both
     * insert. Must be called only from within an active DB::transaction().
     */
    public function findByReservationForUpdate(int $reservationId): ?Payment;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Payment;

    /**
     * Persist $data onto $payment and return the refreshed model. Pure
     * persistence — the caller (PaymentWorkflowService) owns every workflow
     * decision, including the PaymentStateMachine guard.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Payment $payment, array $data): Payment;
}
