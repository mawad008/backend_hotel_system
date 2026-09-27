<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Services\ServiceOrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ServiceOrder\StoreServiceOrderRequest;
use App\Http\Resources\V1\ServiceOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own reservation's service orders
 * (`/api/v1/guest/reservations/{reservation}/service-orders`).
 *
 * Dedicated GUEST controller — reuses ServiceOrderService unchanged (same as
 * ServiceOrderController). The client supplies only service_id/quantity/
 * notes; price, total, status and hotel_id are always derived server-side.
 * No guest-initiated cancel/transition — that stays staff-only pending an
 * approved guest-cancellation rule.
 */
class GuestServiceOrderController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ServiceOrderService $orders,
    ) {}

    public function index(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        return $this->success(ServiceOrderResource::collection(
            $this->orders->listForReservation($found)
        ));
    }

    public function store(StoreServiceOrderRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $order = $this->orders->create($found, $request->validated(), null);

        return $this->success(new ServiceOrderResource($order), __('api.created'), 201);
    }

    public function show(Request $request, int $reservation, int $serviceOrder): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $order = $this->orders->findForReservation($found, $serviceOrder);

        if (! $order) {
            abort(404);
        }

        return $this->success(new ServiceOrderResource($order));
    }

    /**
     * The guest withdraws their own request while it is still cancellable —
     * whatever ServiceOrderStateMachine allows (requested/confirmed →
     * cancelled); a fulfilled or already-cancelled order is a 422. Reuses
     * ServiceOrderService::transition, so a confirmed order's folio charge
     * is voided exactly as a staff cancellation would.
     */
    public function cancel(Request $request, int $reservation, int $serviceOrder): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $found = $this->reservationFor($request, $reservation);

        $order = $this->orders->findForReservation($found, $serviceOrder);

        if (! $order) {
            abort(404);
        }

        $order = $this->orders->transition($order, ServiceOrder::STATUS_CANCELLED, $validated['reason'] ?? null, null);

        return $this->success(new ServiceOrderResource($order), __('api.updated'));
    }

    private function reservationFor(Request $request, int $reservation)
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        return $found;
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
