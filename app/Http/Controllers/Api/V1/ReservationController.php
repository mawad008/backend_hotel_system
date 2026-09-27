<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Services\RoomTypeService;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationCancellationService;
use App\Domain\Reservation\Services\ReservationExtensionService;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Services\FolioService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reservation\ExtendReservationRequest;
use App\Http\Requests\Api\V1\Reservation\IndexFrontDeskRequest;
use App\Http\Requests\Api\V1\Reservation\StoreReservationRequest;
use App\Http\Requests\Api\V1\Reservation\TransitionReservationRequest;
use App\Http\Resources\V1\FolioResource;
use App\Http\Resources\V1\ReservationExtensionResource;
use App\Http\Resources\V1\ReservationResource;
use App\Http\Resources\V1\RoomResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly RoomTypeService $roomTypes,
        private readonly ReservationExtensionService $extensions,
        private readonly FolioService $folios,
        private readonly ReservationCancellationService $cancellations,
        private readonly DigitalAccessService $digitalAccess,
    ) {}

    /**
     * Reservations visible here are always resolved from the
     * authenticated user's own hotel access — a client cannot widen this
     * by passing any request parameter.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Reservation::class);

        return $this->success(ReservationResource::collection(
            $this->reservations->listAccessibleBy($request->user())
        ));
    }

    /**
     * The Room Type is resolved first (via the existing Inventory
     * RoomTypeService, not a direct query) purely to authorize against
     * its hotel — there is no route-bound Hotel for a Reservation
     * (Phase 3C decision D3). ReservationService::create() independently
     * re-resolves Room Type/Room/Guest and re-derives hotel_id as the
     * authoritative business-invariant check; this lookup only feeds the
     * Policy.
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        $roomType = $this->roomTypes->find($request->validated('room_type_id'));

        if (! $roomType) {
            abort(404);
        }

        $this->authorize('create', [Reservation::class, $roomType->hotel]);

        $reservation = $this->reservations->create($request->validated(), $request->user());

        return $this->success(new ReservationResource($reservation), __('api.created'), 201);
    }

    /**
     * Scoped through ReservationService::findAccessibleBy() rather than
     * implicit route-model binding (Phase 3C decision D1) — a reservation
     * that does not exist and one that exists but belongs to a hotel the
     * user cannot access are both a plain 404, never a 403 that would
     * leak its existence.
     */
    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('view', $found);

        return $this->success(new ReservationResource($found));
    }

    /**
     * The single endpoint for advancing a Reservation through the approved
     * state machine (Phase 4A/4B). Resolution and scoping mirror show():
     * the reservation is fetched through ReservationService::findAccessibleBy()
     * — not implicit route-model binding — so a cross-hotel or non-existent
     * id is an identical plain 404. The client supplies only target_status;
     * the acting user is taken from the token, never the request body.
     * Structural transition validity stays in ReservationStateMachine (via
     * the service) and an illegal transition surfaces as the standard 422.
     */
    public function transition(TransitionReservationRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('transition', $found);

        // A staff cancellation goes through the cancellation policy so the
        // deposit is released when the guest is entitled to a refund.
        $updated = $request->validated('target_status') === Reservation::STATUS_CANCELLED
            ? $this->cancellations->cancelByStaff($found, $request->user(), $request->input('reason'))
            : $this->reservations->transitionTo(
                $found,
                $request->validated('target_status'),
                $request->user(),
            );

        return $this->success(new ReservationResource($updated), __('api.updated'));
    }

    /**
     * GET /api/v1/reservations/{reservation}/assignable-rooms — the rooms
     * the front desk can pick for this stay (server-decided availability).
     */
    public function assignableRooms(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('assignRoom', $found);

        return $this->success(RoomResource::collection($this->reservations->assignableRooms($found)));
    }

    /**
     * POST /api/v1/reservations/{reservation}/room — the front desk assigns
     * (or moves) the reservation's physical room. Validation of hotel/room
     * type/availability lives in ReservationService::assignRoom.
     */
    public function assignRoom(Request $request, int $reservation): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'integer'],
        ]);

        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('assignRoom', $found);

        $updated = $this->reservations->assignRoom($found, (int) $validated['room_id'], $request->user());

        return $this->success(new ReservationResource($updated), __('api.updated'));
    }

    /**
     * Dashboard equivalent of the guest Extend Stay endpoint — same
     * ReservationExtensionService, same eligibility/availability/pricing
     * rules, the acting staff user recorded on the extension row.
     */
    public function extend(ExtendReservationRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('extend', $found);

        $extension = $this->extensions->extend(
            $found,
            CarbonImmutable::parse($request->newCheckOut()),
            actor: $request->user(),
            idempotencyKey: $request->idempotencyKey(),
        );

        $updated = $this->reservations->findAccessibleBy($request->user(), $reservation);
        $this->digitalAccess->syncExpiryToStay($updated, $request->user());

        return $this->success([
            'reservation' => new ReservationResource($updated),
            'extension' => new ReservationExtensionResource($extension),
            'folio' => new FolioResource($this->folios->folioFor($updated)),
        ], __('api.updated'));
    }

    /**
     * GET /api/v1/hotels/{hotel}/arrivals
     *
     * Front-desk arrivals for one date (defaults to today).
     */
    public function arrivals(IndexFrontDeskRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewFrontDesk', [Reservation::class, $hotel]);

        $date = $request->requestedDate() ?? CarbonImmutable::now()->toDateString();

        return $this->success(ReservationResource::collection(
            $this->reservations->arrivalsForHotel($hotel->id, $date, $request->perPage())
        ), __('api.front_desk.arrivals'));
    }

    /**
     * GET /api/v1/hotels/{hotel}/departures
     *
     * Front-desk departures for one date (defaults to today).
     */
    public function departures(IndexFrontDeskRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewFrontDesk', [Reservation::class, $hotel]);

        $date = $request->requestedDate() ?? CarbonImmutable::now()->toDateString();

        return $this->success(ReservationResource::collection(
            $this->reservations->departuresForHotel($hotel->id, $date, $request->perPage())
        ), __('api.front_desk.departures'));
    }

    /**
     * GET /api/v1/hotels/{hotel}/in-house
     *
     * Every reservation currently occupying a room at this hotel.
     */
    public function inHouse(IndexFrontDeskRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewFrontDesk', [Reservation::class, $hotel]);

        return $this->success(ReservationResource::collection(
            $this->reservations->inHouseForHotel($hotel->id, $request->perPage())
        ), __('api.front_desk.in_house'));
    }
}
