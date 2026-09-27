<?php

namespace App\Domain\StayServices\Repositories\Contracts;

use App\Domain\StayServices\Models\FolioCharge;
use Illuminate\Database\Eloquent\Collection;

interface FolioChargeRepositoryInterface
{
    /**
     * Every charge for a reservation, newest first.
     *
     * @return Collection<int, FolioCharge>
     */
    public function allForReservation(int $reservationId): Collection;

    public function find(int $id): ?FolioCharge;

    /**
     * The charge produced by a given source, or null. Used to keep charge
     * creation idempotent alongside the `(source_type, source_id)` UNIQUE
     * constraint.
     */
    public function findBySource(string $sourceType, int $sourceId): ?FolioCharge;

    /**
     * `findBySource()` under a row lock. Call only from within an active
     * DB::transaction().
     */
    public function findBySourceForUpdate(string $sourceType, int $sourceId): ?FolioCharge;

    /**
     * The decimal-string sum of `total_amount` for a reservation's charges
     * in the given statuses. Returns a canonical "0.00" when there are none.
     *
     * @param  list<string>  $statuses
     */
    public function sumTotalForReservation(int $reservationId, array $statuses): string;

    /**
     * The same sum as sumTotalForReservation(), computed for every
     * reservation of $hotelId at once — the folio ledger's efficient
     * "which reservations have an outstanding balance" data source (a
     * single grouped aggregate, never one query per reservation).
     *
     * @param  list<string>  $statuses
     * @return array<int, string> reservation_id => decimal-string sum
     */
    public function sumsGroupedByReservationForHotel(int $hotelId, array $statuses): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FolioCharge;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FolioCharge $charge, array $data): FolioCharge;
}
