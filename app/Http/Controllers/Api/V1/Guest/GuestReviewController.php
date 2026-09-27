<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Discovery\Services\HotelDiscoveryService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\Review\Services\ReviewCategoryService;
use App\Domain\Review\Services\ReviewService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Review\SubmitReviewRequest;
use App\Http\Resources\V1\ReviewCategoryResource;
use App\Http\Resources\V1\ReviewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own review of one of their reservations
 * (`/api/v1/guest/reservations/{reservation}/review`), plus the public,
 * unauthenticated read of a hotel's published reviews
 * (`/api/v1/guest/hotels/{hotel}/reviews`) — matches the "browse without
 * login" rule the rest of discovery follows.
 *
 * Dedicated GUEST controller reusing ReviewService unchanged. Eligibility,
 * ownership, the one-review-per-stay rule and moderation are all resolved
 * server-side — the client sends a rating, optional text and optional
 * ratings for the hotel's dynamic review categories.
 */
class GuestReviewController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReviewService $reviews,
        private readonly HotelDiscoveryService $discovery,
        private readonly ReviewCategoryService $categoryService,
    ) {}

    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $review = $this->reviews->reviewFor($found);

        if ($review === null) {
            abort(404);
        }

        $review->loadMissing('categoryRatings');

        return $this->success(new ReviewResource($review), __('api.reviews.status'));
    }

    public function store(SubmitReviewRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $result = $this->reviews->submitForReservation(
            $found, $request->rating(), $request->text(), null, $request->categoryRatings(),
        );
        $result['review']->loadMissing('categoryRatings');

        return $this->success(
            new ReviewResource($result['review']),
            __($result['created'] ? 'api.reviews.submitted' : 'api.reviews.already_reviewed'),
            $result['created'] ? 201 : 200,
        );
    }

    /**
     * Public, unauthenticated — the hotel's **active** review categories in
     * display order. The guest review form builds its per-category rows from
     * this; categories are dashboard-managed, never hardcoded.
     */
    public function categories(int $hotel): JsonResponse
    {
        $found = $this->discovery->findHotel($hotel);

        if (! $found) {
            abort(404);
        }

        return $this->success(
            ReviewCategoryResource::collection($this->categoryService->forHotel($found, activeOnly: true)),
            __('api.reviews.categories'),
        );
    }

    /**
     * Public, unauthenticated — published reviews only. A hotel that does
     * not exist and one that is deactivated are an identical plain 404
     * (same rule as the rest of GuestDiscoveryController).
     */
    public function forHotel(Request $request, int $hotel): JsonResponse
    {
        $found = $this->discovery->findHotel($hotel);

        if (! $found) {
            abort(404);
        }

        $reviews = $this->reviews->publishedForHotel($found->id, (int) $request->query('per_page', 15));

        return $this->success(ReviewResource::collection($reviews), __('api.reviews.list'));
    }

    private function reservationFor(Request $request, int $reservation): Reservation
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
