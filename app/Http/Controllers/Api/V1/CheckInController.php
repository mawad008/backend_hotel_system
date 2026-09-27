<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DigitalAccess\CheckInRequest;
use App\Http\Resources\V1\AccessGrantResource;
use Illuminate\Http\JsonResponse;

/**
 * Phase 7 — the check-in HTTP surface (Phase 0 §16:
 * /api/v1/check-in/{reservation}).
 *
 * Thin: it resolves and authorizes the Reservation (through
 * ReservationService, not implicit route-model binding, so a cross-hotel or
 * missing id is an identical plain 404), hands validated input to
 * DigitalAccessService, and maps the resulting grant to an HTTP response. It
 * never calls the provider, opens a transaction, touches a model for a
 * workflow decision, transitions a Reservation, or reimplements a state
 * machine.
 */
class CheckInController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly DigitalAccessService $access,
    ) {}

    /**
     * POST /api/v1/check-in/{reservation}
     */
    public function store(CheckInRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('checkIn', [AccessGrant::class, $found]);

        $grant = $this->access->checkIn(
            reservation: $found,
            directive: $request->simulationDirective(),
            idempotencyKey: $request->idempotencyKey(),
            actor: $request->user(),
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
}
