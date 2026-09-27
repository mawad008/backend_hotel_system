<?php

namespace App\Domain\StayServices\Repositories;

use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Repositories\Contracts\FolioChargeRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentFolioChargeRepository implements FolioChargeRepositoryInterface
{
    public function allForReservation(int $reservationId): Collection
    {
        return FolioCharge::query()
            ->where('reservation_id', $reservationId)
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id): ?FolioCharge
    {
        return FolioCharge::query()->find($id);
    }

    public function findBySource(string $sourceType, int $sourceId): ?FolioCharge
    {
        return FolioCharge::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    public function findBySourceForUpdate(string $sourceType, int $sourceId): ?FolioCharge
    {
        return FolioCharge::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();
    }

    public function sumTotalForReservation(int $reservationId, array $statuses): string
    {
        if ($statuses === []) {
            return '0.00';
        }

        // SUM() is computed by the database over the DECIMAL column and
        // returned as a string; bcadd normalizes it to two places without
        // any float arithmetic. A null result (no rows) becomes "0.00".
        $sum = FolioCharge::query()
            ->where('reservation_id', $reservationId)
            ->whereIn('status', $statuses)
            ->selectRaw('COALESCE(SUM(total_amount), 0) as aggregate')
            ->value('aggregate');

        return bcadd((string) $sum, '0', 2);
    }

    public function sumsGroupedByReservationForHotel(int $hotelId, array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }

        return FolioCharge::query()
            ->where('hotel_id', $hotelId)
            ->whereIn('status', $statuses)
            ->groupBy('reservation_id')
            ->selectRaw('reservation_id, COALESCE(SUM(total_amount), 0) as aggregate')
            ->pluck('aggregate', 'reservation_id')
            ->map(fn ($v) => bcadd((string) $v, '0', 2))
            ->all();
    }

    public function create(array $data): FolioCharge
    {
        return FolioCharge::create($data)->refresh();
    }

    public function update(FolioCharge $charge, array $data): FolioCharge
    {
        $charge->update($data);

        return $charge->refresh();
    }
}
