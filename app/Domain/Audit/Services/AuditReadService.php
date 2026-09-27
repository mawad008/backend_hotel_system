<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The read side of the audit trail — pure reads, no writes. Kept separate
 * from AuditLogger (write-only, used throughout every domain to record an
 * event) so no consumer of "record an event" ever gains a dependency on
 * the query/report path, and vice versa.
 */
class AuditReadService
{
    public function __construct(private readonly AuditLogRepositoryInterface $auditLogs) {}

    /**
     * @param  array{actor_id?: int|null, action?: string|null, auditable_type?: string|null, from?: string|null, to?: string|null}  $filters
     */
    public function listForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->auditLogs->paginateForHotel($hotelId, $filters, $perPage);
    }

    /**
     * @param  array{actor_id?: int|null, action?: string|null, auditable_type?: string|null, hotel_id?: int|null, from?: string|null, to?: string|null}  $filters
     */
    public function listAll(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->auditLogs->paginateAll($filters, $perPage);
    }
}
