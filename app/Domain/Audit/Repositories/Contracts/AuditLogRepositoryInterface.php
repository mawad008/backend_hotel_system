<?php

namespace App\Domain\Audit\Repositories\Contracts;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AuditLogRepositoryInterface
{
    /**
     * Every audit event recorded against $hotelId, newest first.
     *
     * @param  array{actor_id?: int|null, action?: string|null, auditable_type?: string|null, from?: string|null, to?: string|null}  $filters
     */
    public function paginateForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Every audit event group-wide, including hotel-independent actions
     * (hotel_id null — e.g. user/role management) — Group Owner only.
     *
     * @param  array{actor_id?: int|null, action?: string|null, auditable_type?: string|null, hotel_id?: int|null, from?: string|null, to?: string|null}  $filters
     */
    public function paginateAll(array $filters, int $perPage): LengthAwarePaginator;
}
