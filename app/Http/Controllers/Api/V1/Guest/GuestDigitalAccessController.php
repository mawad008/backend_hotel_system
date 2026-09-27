<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AccessGrantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own digital access grant status
 * (`/api/v1/guest/reservations/{reservation}/access`). Read-only — revoke
 * stays staff-only; there is no approved guest self-service revoke rule.
 */
class GuestDigitalAccessController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly DigitalAccessService $access,
    ) {}

    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        $grant = $this->access->currentStatusFor($found);

        return $this->success(new AccessGrantResource($grant), __('api.digital_access.status'));
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
