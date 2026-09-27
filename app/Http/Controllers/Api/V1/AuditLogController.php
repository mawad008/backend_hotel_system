<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditReadService;
use App\Domain\HotelGroup\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Audit\IndexAuditLogRequest;
use App\Http\Resources\V1\AuditLogResource;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditReadService $auditLogs) {}

    /**
     * GET /api/v1/hotels/{hotel}/audit-log
     */
    public function forHotel(IndexAuditLogRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewForHotel', [AuditLog::class, $hotel]);

        $logs = $this->auditLogs->listForHotel($hotel->id, $request->filters(), $request->perPage());
        $logs->loadMissing('actor');

        return $this->success(AuditLogResource::collection($logs), __('api.audit.log'));
    }

    /**
     * GET /api/v1/audit-log
     *
     * Group Owner only — spans every hotel, plus hotel-independent events
     * (hotel_id null: user/role management). An optional `hotel_id` query
     * param narrows it without changing the authorization boundary.
     */
    public function global(IndexAuditLogRequest $request): JsonResponse
    {
        $this->authorize('viewGlobal', AuditLog::class);

        $filters = $request->filters();
        $filters['hotel_id'] = $request->hotelId();

        $logs = $this->auditLogs->listAll($filters, $request->perPage());
        $logs->loadMissing('actor');

        return $this->success(AuditLogResource::collection($logs), __('api.audit.log'));
    }
}
