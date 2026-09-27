<?php

namespace App\Domain\Audit\Repositories;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentAuditLogRepository implements AuditLogRepositoryInterface
{
    public function paginateForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->applyFilters(AuditLog::query()->where('hotel_id', $hotelId), $filters)
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateAll(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->applyFilters(AuditLog::query(), $filters)
            ->when(
                ($filters['hotel_id'] ?? null) !== null,
                fn (Builder $q) => $q->where('hotel_id', $filters['hotel_id']),
            )
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(($filters['actor_id'] ?? null) !== null, fn (Builder $q) => $q->where('actor_id', $filters['actor_id']))
            ->when(($filters['action'] ?? null) !== null, fn (Builder $q) => $q->where('action', 'like', $filters['action'].'%'))
            ->when(($filters['auditable_type'] ?? null) !== null, fn (Builder $q) => $q->where('auditable_type', $filters['auditable_type']))
            ->when(($filters['from'] ?? null) !== null, fn (Builder $q) => $q->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(($filters['to'] ?? null) !== null, fn (Builder $q) => $q->where('created_at', '<=', $filters['to'].' 23:59:59'));
    }
}
