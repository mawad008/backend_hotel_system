<?php

namespace App\Domain\StayServices\Repositories\Contracts;

use App\Domain\StayServices\Models\ServiceOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ServiceOrderRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<ServiceOrder>
     */
    public function paginateForReservation(int $reservationId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Every order for a reservation, newest first — used to build the folio
     * read model.
     *
     * @return Collection<int, ServiceOrder>
     */
    public function allForReservation(int $reservationId): Collection;

    public function find(int $id): ?ServiceOrder;

    /**
     * Order counts by status, plus revenue grouped by currency (SUM of
     * total_amount for CONFIRMED/FULFILLED orders only — the statuses a
     * folio charge has actually been posted for; never summed across
     * currencies), for $hotelId, requested within [$from, $to] — the
     * services report's data source.
     *
     * @return array{by_status: array<string, int>, revenue: list<array{currency: string, amount: string}>}
     */
    public function statsForHotel(int $hotelId, string $from, string $to): array;

    /**
     * `find()` under a `SELECT ... FOR UPDATE` row lock. Call only from
     * within an active DB::transaction().
     */
    public function findForUpdate(int $id): ?ServiceOrder;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ServiceOrder;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ServiceOrder $order, array $data): ServiceOrder;
}
