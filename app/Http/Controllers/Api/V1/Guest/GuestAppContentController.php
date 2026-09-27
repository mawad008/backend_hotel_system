<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\AppContent\Services\GuestAppContentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\GuestAppContentResource;
use Illuminate\Http\JsonResponse;

/**
 * Anonymous read of the Guest App's branding + entry content
 * (`GET /api/v1/guest/app-content`) — the splash logo / wordmark and the
 * onboarding photo + copy, all managed from the dashboard. Public by design:
 * it is shown before any sign-in.
 */
class GuestAppContentController extends Controller
{
    public function __construct(private readonly GuestAppContentService $contents) {}

    public function show(): JsonResponse
    {
        return $this->success(new GuestAppContentResource($this->contents->current()), meta: [
            // The platform booking rules the app explains (never hardcoded there).
            'booking_policy' => [
                'free_cancellation_hours' => (int) config('guest_booking.free_cancellation_hours'),
                'identity_retention_days' => (int) config('verification.retention_days'),
                'currency' => config('payment.currency'),
            ],
        ]);
    }
}
