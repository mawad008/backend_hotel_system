<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Services\ServiceOrderService;
use App\Domain\StayServices\Services\ServiceReviewService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ServiceReview\SubmitServiceReviewRequest;
use App\Http\Resources\V1\ServiceReviewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own review of one of their service orders
 * (`/api/v1/guest/reservations/{reservation}/service-orders/{serviceOrder}/review`).
 *
 * Mirrors GuestReviewController exactly, one level deeper: ownership is
 * resolved through the reservation (ReservationService::findOwnedByGuest)
 * and then the specific order (ServiceOrderService::findForReservation,
 * which already rejects an order belonging to another reservation) —
 * neither the hotel, the service, nor the order can be a client-trusted
 * value. Eligibility (the order must be `fulfilled`), the
 * one-review-per-order rule and moderation are all resolved server-side.
 */
class GuestServiceReviewController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ServiceOrderService $orders,
        private readonly ServiceReviewService $reviews,
    ) {}

    public function show(Request $request, int $reservation, int $serviceOrder): JsonResponse
    {
        $order = $this->orderFor($request, $reservation, $serviceOrder);

        $review = $this->reviews->reviewFor($order);

        if ($review === null) {
            abort(404);
        }

        return $this->success(new ServiceReviewResource($review));
    }

    public function store(SubmitServiceReviewRequest $request, int $reservation, int $serviceOrder): JsonResponse
    {
        $order = $this->orderFor($request, $reservation, $serviceOrder);

        $result = $this->reviews->submitForServiceOrder(
            $order, $request->rating(), $request->text(), null,
        );

        return $this->success(
            new ServiceReviewResource($result['review']),
            __($result['created'] ? 'api.created' : 'api.reviews.already_reviewed'),
            $result['created'] ? 201 : 200,
        );
    }

    private function orderFor(Request $request, int $reservation, int $serviceOrder)
    {
        $reservationFound = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $reservationFound) {
            abort(404);
        }

        $order = $this->orders->findForReservation($reservationFound, $serviceOrder);

        if (! $order) {
            abort(404);
        }

        return $order;
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
