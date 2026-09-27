<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentWorkflowService;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payment\IndexPaymentRequest;
use App\Http\Requests\Api\V1\Payment\StorePaymentHoldRequest;
use App\Http\Resources\V1\PaymentResource;
use Illuminate\Http\JsonResponse;

/**
 * Phase 5D — the payment HTTP surface. Thin: it resolves and authorizes the
 * Reservation, hands the validated input to PaymentWorkflowService (the
 * Phase 5C business authority), and maps the resulting Payment state to an
 * HTTP response. It never calls the gateway, opens a transaction, touches a
 * model for a workflow decision, transitions a Reservation, or reimplements
 * idempotency / the state machines.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly PaymentWorkflowService $payments,
    ) {}

    /**
     * POST /api/v1/reservations/{reservation}/payment/hold
     *
     * Resolution and scoping mirror ReservationController::transition: the
     * reservation is fetched through ReservationService::findAccessibleBy()
     * — not implicit route-model binding — so a cross-hotel or non-existent
     * id is an identical plain 404 that never leaks existence. The acting
     * user comes from the token; the idempotency key from the
     * `Idempotency-Key` header.
     */
    public function hold(StorePaymentHoldRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('create', [Payment::class, $found]);

        $payment = $this->payments->initiateHold(
            reservation: $found,
            amount: $request->validated('amount'),
            currency: $request->validated('currency'),
            idempotencyKey: $request->idempotencyKey(),
            simulationDirective: $request->simulationDirective(),
            actor: $request->user(),
        );

        return $this->respond($payment);
    }

    /**
     * GET /api/v1/hotels/{hotel}/payments
     *
     * The staff payments ledger for one hotel. Read-only, gated by the
     * separate `payments.view` permission (never `payments.manage`).
     */
    public function index(IndexPaymentRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewLedger', [Payment::class, $hotel]);

        $payments = $this->payments->listForHotel($hotel->id, $request->filters(), $request->perPage());

        return $this->success(PaymentResource::collection($payments), __('api.payment.ledger'));
    }

    /**
     * The resolved Payment state — never a claim beyond it (Phase 5D §14–§16).
     */
    private function respond(Payment $payment): JsonResponse
    {
        return match ($payment->status) {
            Payment::STATUS_HOLD_ACTIVE => $this->success(
                new PaymentResource($payment), __('api.payment.hold_placed'), 201,
            ),
            Payment::STATUS_HOLD_REQUESTED => $this->success(
                new PaymentResource($payment), __('api.payment.hold_pending'), 200,
            ),
            Payment::STATUS_HOLD_FAILED => $this->error(
                __('api.payment.hold_failed'), 422, ['status' => $payment->status],
            ),
            Payment::STATUS_CANCELLED => $this->error(
                __('api.payment.hold_cancelled'), 422, ['status' => $payment->status],
            ),
            Payment::STATUS_EXPIRED => $this->error(
                __('api.payment.hold_expired'), 422, ['status' => $payment->status],
            ),
            default => $this->success(new PaymentResource($payment), __('api.payment.hold_state')),
        };
    }
}
