<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notification\Models\StaffNotification;
use App\Domain\Notification\Services\StaffNotificationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\IndexStaffNotificationRequest;
use App\Http\Resources\V1\StaffNotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated staff user's own dashboard inbox. The recipient is always
 * the token's user — never a request parameter.
 */
class StaffNotificationController extends Controller
{
    public function __construct(private readonly StaffNotificationService $notifications) {}

    /**
     * GET /api/v1/me/notifications — `meta.unread_count` drives the bell badge.
     */
    public function index(IndexStaffNotificationRequest $request): JsonResponse
    {
        $this->authorize('viewInbox', StaffNotification::class);

        $user = $request->user();
        $feed = $this->notifications->listFor($user, $request->unreadOnly(), $request->perPage());

        return $this->success(
            StaffNotificationResource::collection($feed),
            __('api.notifications.feed'),
            meta: ['unread_count' => $this->notifications->unreadCountFor($user)],
        );
    }

    /**
     * GET /api/v1/me/notifications/unread-count — cheap poll for the badge.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorize('viewInbox', StaffNotification::class);

        return $this->success(['unread_count' => $this->notifications->unreadCountFor($request->user())]);
    }

    /**
     * PATCH /api/v1/me/notifications/{notification}/read
     */
    public function markRead(Request $request, int $notification): JsonResponse
    {
        $this->authorize('viewInbox', StaffNotification::class);

        $updated = $this->notifications->markReadFor($request->user(), $notification);

        return $this->success(new StaffNotificationResource($updated->loadMissing('hotel:id,name,name_i18n')), __('api.notifications.read'));
    }

    /**
     * POST /api/v1/me/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $this->authorize('viewInbox', StaffNotification::class);

        $count = $this->notifications->markAllReadFor($request->user());

        return $this->success(['marked_read' => $count], __('api.notifications.read_all'));
    }
}
