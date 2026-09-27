<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\IndexNotificationRequest;
use App\Http\Resources\V1\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 11 — the reservation-scoped notification feed (Phase 0 §4/§16).
 *
 * Thin, same conventions as FolioController / LoyaltyController:
 * {reservation} is an int id resolved through ReservationService, so a
 * cross-hotel or missing id is an identical plain 404. All workflow lives in
 * NotificationService — this never opens a transaction, touches a model for a
 * workflow decision, or calls the provider. The feed shows the `in_app`
 * channel only; `email` / `sms` rows are delivery bookkeeping.
 *
 * The recipient is never client-supplied — it is the reservation's guest,
 * resolved server-side.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * GET /api/v1/reservations/{reservation}/notifications
     */
    public function index(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation, 'viewAny');

        $feed = $this->notifications->listForReservation(
            $found,
            $request->boolean('unread'),
            $this->perPage($request),
        );

        return $this->success(
            NotificationResource::collection($feed),
            __('api.notifications.feed'),
        );
    }

    /**
     * PATCH /api/v1/reservations/{reservation}/notifications/{notification}/read
     */
    public function markRead(Request $request, int $reservation, int $notification): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation, 'markRead');

        $updated = $this->notifications->markReadFor($found, $notification);

        return $this->success(new NotificationResource($updated), __('api.notifications.read'));
    }

    /**
     * POST /api/v1/reservations/{reservation}/notifications/read-all
     */
    public function markAllRead(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation, 'markRead');

        $count = $this->notifications->markAllReadForReservation($found);

        return $this->success(['marked_read' => $count], __('api.notifications.read_all'));
    }

    private function reservationFor(Request $request, int $reservation, string $ability)
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize($ability, [Notification::class, $found]);

        return $found;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 20);

        return max(1, min($perPage, 100));
    }

    /**
     * GET /api/v1/hotels/{hotel}/notifications
     *
     * The staff-wide `in_app` feed for one hotel — every guest notification
     * across the hotel's reservations, not just one.
     */
    public function forHotel(IndexNotificationRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewAnyForHotel', [Notification::class, $hotel]);

        $feed = $this->notifications->listForHotel($hotel->id, $request->filters(), $request->perPage());

        return $this->success(NotificationResource::collection($feed), __('api.notifications.feed'));
    }
}
