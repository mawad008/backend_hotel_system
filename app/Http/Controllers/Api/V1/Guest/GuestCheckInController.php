<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DigitalAccess\CheckInRequest;
use App\Http\Resources\V1\AccessGrantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own check-in
 * (`/api/v1/guest/reservations/{reservation}/check-in`).
 *
 * Dedicated GUEST controller — reuses DigitalAccessService::checkIn
 * unchanged (same as CheckInController). The service independently
 * re-verifies eligibility (VERIFIED + payment HOLD_ACTIVE + identity
 * AUTO_APPROVED/STAFF_APPROVED + time eligibility) — this controller cannot
 * and does not skip any of it. Ownership is enforced via
 * ReservationService::findOwnedByGuest.
 */
class GuestCheckInController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly DigitalAccessService $access,
    ) {}

    public function store(CheckInRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        $grant = $this->access->checkIn(
            reservation: $found,
            directive: $request->simulationDirective(),
            idempotencyKey: $request->idempotencyKey(),
            actor: null,
        );

        return match ($grant->status) {
            AccessGrant::STATUS_ACTIVE => $this->success(
                new AccessGrantResource($grant), __('api.digital_access.checked_in'), 201,
            ),
            AccessGrant::STATUS_FAILED => $this->error(
                __('api.digital_access.issue_failed'), 422, ['status' => $grant->status],
            ),
            default => $this->success(new AccessGrantResource($grant), __('api.digital_access.state')),
        };
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
