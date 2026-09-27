<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Services\FolioService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FolioResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own reservation's folio
 * (`/api/v1/guest/reservations/{reservation}/folio`). Read-only, reuses
 * FolioService unchanged.
 */
class GuestFolioController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly FolioService $folios,
    ) {}

    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        return $this->success(
            new FolioResource($this->folios->folioFor($found)),
            __('api.stay_services.folio'),
        );
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
