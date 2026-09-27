<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Checkout\IndexSettlementRequest;
use App\Http\Requests\Api\V1\Checkout\PerformCheckoutRequest;
use App\Http\Resources\V1\CheckoutResource;
use App\Http\Resources\V1\SettlementResource;
use Illuminate\Http\JsonResponse;

/**
 * Phase 9D — the checkout HTTP surface (Phase 0 §16).
 *
 * Thin, same conventions as CheckInController / PaymentController:
 * {reservation} is an int id resolved through ReservationService (not
 * route-model binding), so a cross-hotel or missing id is an identical plain
 * 404. The workflow lives entirely in CheckoutService — this never calls the
 * gateway, opens a transaction, touches a model for a workflow decision,
 * transitions a reservation, or reimplements a state machine.
 *
 * The client supplies no amount: the settlement amount is always computed
 * server-side from the authoritative folio.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly CheckoutService $checkouts,
    ) {}

    /**
     * POST /api/v1/reservations/{reservation}/checkout
     */
    public function store(PerformCheckoutRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('perform', [Checkout::class, $found]);

        $result = $this->checkouts->checkout(
            reservation: $found,
            idempotencyKey: $request->idempotencyKey(),
            directive: $request->simulationDirective(),
            actor: $request->user(),
        );

        if ($result->isSettlementPending()) {
            return $this->error(__('api.checkout.settlement_pending'), 422, [
                'checkout_status' => $result->checkout->status,
                'payment_status' => $result->payment?->status,
            ]);
        }

        if ($result->isSettlementFailed()) {
            return $this->error(__('api.checkout.settlement_failed'), 422, [
                'checkout_status' => $result->checkout->status,
                'payment_status' => $result->payment?->status,
            ]);
        }

        // A completed checkout is returned as 200 whether this call finalized
        // it or replayed an already-completed one — the operation is
        // idempotent and the body carries the full authoritative state.
        return $this->success(new CheckoutResource($result), __('api.checkout.completed'));
    }

    /**
     * GET /api/v1/hotels/{hotel}/settlements
     *
     * The staff settlements ledger for one hotel.
     */
    public function index(IndexSettlementRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewLedger', [Checkout::class, $hotel]);

        $settlements = $this->checkouts->listForHotel($hotel->id, $request->filters(), $request->perPage());

        return $this->success(SettlementResource::collection($settlements), __('api.checkout.settlements_ledger'));
    }
}
