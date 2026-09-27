<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentWorkflowService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Guest\InitiateGuestPaymentHoldRequest;
use App\Http\Resources\V1\Guest\GuestPaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's view of, and write path into, their
 * reservation's deposit payment (`/api/v1/guest/reservations/{reservation}/payment`).
 *
 * Architecture: dedicated GUEST controller/resource, thin over the shared
 * PaymentWorkflowService — mirrors the staff PaymentController::hold()
 * response mapping exactly. Authorization is ownership (guest_id === token
 * guest); a non-owned or missing reservation is an identical plain 404.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DEPOSIT AMOUNT
 * ─────────────────────────────────────────────────────────────────────────
 * PaymentWorkflowService::initiateHold() requires an approved deposit
 * amount. Each hotel sets its own pre-booking deposit as a percentage of
 * the booked room price (`hotels.deposit_percentage`, dashboard hotel
 * form): the hold is that % of the reservation's price_snapshot — a 100
 * SAR booking at 10% holds 10 SAR. A hotel with no percentage on file
 * refuses with a machine-readable `deposit_amount_rule_undefined` reason.
 */
class GuestPaymentController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly PaymentWorkflowService $payments,
    ) {}

    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        $payment = $found->payment()->first();

        if ($payment === null) {
            return $this->success(null, __('api.guest_booking.no_payment'));
        }

        return $this->success(new GuestPaymentResource($payment));
    }

    public function hold(InitiateGuestPaymentHoldRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        $percentage = $found->hotel()->value('deposit_percentage');

        if ($percentage === null) {
            return $this->error(
                __('api.guest_booking.deposit_rule_undefined'),
                422,
                ['reason' => 'deposit_amount_rule_undefined'],
            );
        }

        // A 0% deposit hotel takes no hold: the reservation is confirmed
        // straight away (payment confirms, it never starts the stay).
        if (bccomp((string) $percentage, '0', 2) === 0) {
            if ($found->status === Reservation::STATUS_PENDING) {
                $found = $this->reservations->transitionTo($found, Reservation::STATUS_DEPOSIT_HELD);
            }

            return $this->success(null, __('api.reservation.deposit_not_required'), 200, ['deposit_required' => false]);
        }

        $payment = $this->payments->initiateHold(
            reservation: $found,
            amount: $found->hotel->depositFor((string) $found->price_snapshot),
            // The reservation's snapshotted currency, never today's config.
            currency: $found->currency,
            idempotencyKey: $request->idempotencyKey(),
        );

        return $this->respond($payment);
    }

    private function respond(Payment $payment): JsonResponse
    {
        return match ($payment->status) {
            Payment::STATUS_HOLD_ACTIVE => $this->success(
                new GuestPaymentResource($payment), __('api.payment.hold_placed'), 201,
            ),
            Payment::STATUS_HOLD_REQUESTED => $this->success(
                new GuestPaymentResource($payment), __('api.payment.hold_pending'), 200,
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
            default => $this->success(new GuestPaymentResource($payment), __('api.payment.hold_state')),
        };
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
