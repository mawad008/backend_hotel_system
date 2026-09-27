<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Services\Folio;
use App\Domain\StayServices\Services\FolioService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Folio\IndexFolioRequest;
use App\Http\Resources\V1\FolioResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8 — the reservation folio surface (Phase 0 §16:
 * /api/v1/reservations/{reservation}/folio). Read-only. {reservation} is an
 * int id resolved through ReservationService, so a cross-hotel or missing
 * id is an identical plain 404.
 */
class FolioController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly FolioService $folios,
    ) {}

    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('view', [Folio::class, $found]);

        return $this->success(
            new FolioResource($this->folios->folioFor($found)),
            __('api.stay_services.folio'),
        );
    }

    /**
     * GET /api/v1/hotels/{hotel}/folios
     *
     * The standalone folio ledger for one hotel. Every row's totals are
     * computed by the same FolioService::folioFor() the reservation-scoped
     * read uses — this never recomputes the money maths itself.
     */
    public function index(IndexFolioRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewForHotel', [Folio::class, $hotel]);

        $reservations = $this->folios->listForHotel($hotel->id, $request->filters(), $request->perPage());
        $folios = collect($reservations->items())->map(fn ($reservation) => $this->folios->folioFor($reservation));
        $reservations->setCollection($folios);

        return $this->success(FolioResource::collection($reservations), __('api.stay_services.folio_ledger'));
    }
}
