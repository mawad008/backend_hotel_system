<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own notification feed, reached through one of
 * their reservations (`/api/v1/guest/reservations/{reservation}/notifications*`).
 *
 * Dedicated GUEST controller — reuses NotificationService unchanged (same as
 * NotificationController). Ownership via ReservationService::findOwnedByGuest
 * guarantees the guest can never reach another guest's feed or any
 * staff-wide notification: the recipient is always this reservation's own
 * guest, resolved server-side.
 */
class GuestNotificationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

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

    public function markRead(Request $request, int $reservation, int $notification): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $updated = $this->notifications->markReadFor($found, $notification);

        return $this->success(new NotificationResource($updated), __('api.notifications.read'));
    }

    public function markAllRead(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $count = $this->notifications->markAllReadForReservation($found);

        return $this->success(['marked_read' => $count], __('api.notifications.read_all'));
    }

    /**
     * The guest's feed across every reservation (the app's bell + list).
     * `meta.unread_count` drives the badge.
     */
    public function feed(Request $request): JsonResponse
    {
        $guest = $this->guest($request);

        return $this->success(
            NotificationResource::collection(
                $this->notifications->listForGuest($guest, $request->boolean('unread'), $this->perPage($request)),
            ),
            __('api.notifications.feed'),
            meta: ['unread_count' => $this->notifications->unreadCountForGuest($guest)],
        );
    }

    public function markFeedRead(Request $request, int $notification): JsonResponse
    {
        $updated = $this->notifications->markReadForGuest($this->guest($request), $notification);

        return $this->success(new NotificationResource($updated), __('api.notifications.read'));
    }

    public function markFeedAllRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllReadForGuest($this->guest($request));

        return $this->success(['marked_read' => $count], __('api.notifications.read_all'));
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

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 20);

        return max(1, min($perPage, 100));
    }
}
