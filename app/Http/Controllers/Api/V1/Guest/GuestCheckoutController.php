<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Checkout\PerformCheckoutRequest;
use App\Http\Resources\V1\CheckoutResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own reservation checkout
 * (`/api/v1/guest/reservations/{reservation}/checkout`). Dedicated GUEST
 * controller — reuses CheckoutService unchanged (same as
 * CheckoutController); the settlement amount is always computed
 * server-side from the authoritative folio, never client-supplied.
 */
class GuestCheckoutController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly CheckoutService $checkouts,
    ) {}

    public function store(PerformCheckoutRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        $result = $this->checkouts->checkout(
            reservation: $found,
            idempotencyKey: $request->idempotencyKey(),
            directive: $request->simulationDirective(),
            actor: null,
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

        return $this->success(new CheckoutResource($result), __('api.checkout.completed'));
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
