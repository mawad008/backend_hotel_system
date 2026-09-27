<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Services\ServiceOrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ServiceOrder\StoreServiceOrderRequest;
use App\Http\Requests\Api\V1\ServiceOrder\TransitionServiceOrderRequest;
use App\Http\Resources\V1\ServiceOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8 — the reservation service-order surface (Phase 0 §16:
 * /api/v1/reservations/{reservation}/service-orders).
 *
 * Thin, same conventions as CheckInController / DigitalAccessController:
 * {reservation} is an int id resolved through ReservationService (not
 * route-model binding), so a cross-hotel or missing id is an identical
 * plain 404; the workflow lives in ServiceOrderService. Client-supplied
 * hotel_id / prices / totals / status are never read.
 */
class ServiceOrderController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ServiceOrderService $orders,
    ) {}

    public function index(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('viewAny', [ServiceOrder::class, $found]);

        return $this->success(ServiceOrderResource::collection(
            $this->orders->listForReservation($found)
        ));
    }

    public function store(StoreServiceOrderRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('create', [ServiceOrder::class, $found]);

        $order = $this->orders->create($found, $request->validated(), $request->user());

        return $this->success(new ServiceOrderResource($order), __('api.created'), 201);
    }

    public function show(Request $request, int $reservation, int $serviceOrder): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('view', [ServiceOrder::class, $found]);

        $order = $this->orders->findForReservation($found, $serviceOrder);

        if (! $order) {
            abort(404);
        }

        return $this->success(new ServiceOrderResource($order));
    }

    public function transition(TransitionServiceOrderRequest $request, int $reservation, int $serviceOrder): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('transition', [ServiceOrder::class, $found]);

        $order = $this->orders->findForReservation($found, $serviceOrder);

        if (! $order) {
            abort(404);
        }

        $order = $this->orders->transition(
            $order,
            $request->validated('target_status'),
            $request->validated('reason'),
            $request->user(),
        );

        return $this->success(new ServiceOrderResource($order), __('api.updated'));
    }
}
