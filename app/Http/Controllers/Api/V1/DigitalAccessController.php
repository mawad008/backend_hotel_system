<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DigitalAccess\RevokeAccessRequest;
use App\Http\Resources\V1\AccessGrantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 7 — the digital access HTTP surface (Phase 0 §16:
 * /api/v1/access/{reservation}, /{reservation}/revoke).
 *
 * Thin, same conventions as CheckInController: reservation resolved and
 * authorized through ReservationService; the workflow lives in
 * DigitalAccessService.
 */
class DigitalAccessController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly DigitalAccessService $access,
    ) {}

    /**
     * GET /api/v1/access/{reservation}
     */
    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('view', [AccessGrant::class, $found]);

        $grant = $this->access->currentStatusFor($found);

        return $this->success(new AccessGrantResource($grant), __('api.digital_access.status'));
    }

    /**
     * POST /api/v1/access/{reservation}/revoke
     */
    public function revoke(RevokeAccessRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('revoke', [AccessGrant::class, $found]);

        $grant = $this->access->revoke(
            reservation: $found,
            reason: $request->reason(),
            directive: $request->simulationDirective(),
            actor: $request->user(),
        );

        return $this->success(new AccessGrantResource($grant), __('api.digital_access.revoked'));
    }
}
