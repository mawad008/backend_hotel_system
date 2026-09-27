<?php

namespace App\Domain\Support\Repositories;

use App\Domain\Support\Models\ProblemReport;
use App\Domain\Support\Repositories\Contracts\ProblemReportRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentProblemReportRepository implements ProblemReportRepositoryInterface
{
    public function find(int $id): ?ProblemReport
    {
        return ProblemReport::query()->find($id);
    }

    public function create(array $data): ProblemReport
    {
        return ProblemReport::create($data)->refresh();
    }

    public function update(ProblemReport $report, array $data): ProblemReport
    {
        $report->update($data);

        return $report->refresh();
    }

    public function paginateForReservation(int $reservationId, int $perPage = 15): LengthAwarePaginator
    {
        return ProblemReport::query()
            ->where('reservation_id', $reservationId)
            ->latest()
            ->paginate($perPage);
    }

    public function paginateForHotel(int $hotelId, ?string $status, int $perPage = 15): LengthAwarePaginator
    {
        return ProblemReport::query()
            ->where('hotel_id', $hotelId)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate($perPage);
    }
}
