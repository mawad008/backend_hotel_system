<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Services\ReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Report\IndexReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Staff reporting — occupancy, revenue, hotel comparison. Every figure is
 * an aggregate over Room/Reservation/Payment, computed by ReportService;
 * this controller only validates the query and gates on `reports.view`
 * (a plain Gate, not a Policy — a report has no single owning Model).
 * Not a JsonResource: an aggregate is a plain array, not a per-row
 * representation of a persisted entity.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * GET /api/v1/reports/occupancy
     */
    public function occupancy(IndexReportRequest $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return $this->success(
            $this->reports->occupancy($request->user(), $request->hotelId(), $request->from(), $request->to()),
            __('api.reports.occupancy'),
        );
    }

    /**
     * GET /api/v1/reports/revenue
     */
    public function revenue(IndexReportRequest $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return $this->success(
            $this->reports->revenue($request->user(), $request->hotelId(), $request->from(), $request->to()),
            __('api.reports.revenue'),
        );
    }

    /**
     * GET /api/v1/reports/hotel-comparison
     */
    public function hotelComparison(IndexReportRequest $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return $this->success(
            $this->reports->hotelComparison($request->user(), $request->from(), $request->to()),
            __('api.reports.hotel_comparison'),
        );
    }

    /**
     * GET /api/v1/reports/reservations
     */
    public function reservations(IndexReportRequest $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return $this->success(
            $this->reports->reservations($request->user(), $request->hotelId(), $request->from(), $request->to()),
            __('api.reports.reservations'),
        );
    }

    /**
     * GET /api/v1/reports/payments
     */
    public function payments(IndexReportRequest $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return $this->success(
            $this->reports->payments($request->user(), $request->hotelId(), $request->from(), $request->to()),
            __('api.reports.payments'),
        );
    }

    /**
     * GET /api/v1/reports/services
     */
    public function services(IndexReportRequest $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return $this->success(
            $this->reports->services($request->user(), $request->hotelId(), $request->from(), $request->to()),
            __('api.reports.services'),
        );
    }

    /**
     * GET /api/v1/reports/loyalty
     */
    public function loyalty(IndexReportRequest $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return $this->success(
            $this->reports->loyalty($request->user(), $request->hotelId(), $request->from(), $request->to()),
            __('api.reports.loyalty'),
        );
    }

    /**
     * GET /api/v1/reports/reviews
     */
    public function reviews(IndexReportRequest $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return $this->success(
            $this->reports->reviews($request->user(), $request->hotelId(), $request->from(), $request->to()),
            __('api.reports.reviews'),
        );
    }
}
