<?php

namespace App\Domain\Support\Repositories\Contracts;

use App\Domain\Support\Models\ProblemReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProblemReportRepositoryInterface
{
    public function find(int $id): ?ProblemReport;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ProblemReport;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProblemReport $report, array $data): ProblemReport;

    public function paginateForReservation(int $reservationId, int $perPage = 15): LengthAwarePaginator;

    public function paginateForHotel(int $hotelId, ?string $status, int $perPage = 15): LengthAwarePaginator;
}
