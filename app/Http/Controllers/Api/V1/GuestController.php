<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Services\GuestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Guests\IndexGuestRequest;
use App\Http\Requests\Api\V1\Guests\StoreGuestRequest;
use App\Http\Resources\V1\GuestResource;
use App\Http\Resources\V1\ReservationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff-facing guest directory. Mostly read-only (a Guest's own data is
 * normally mutated only through the guest app's own profile flow); store()
 * is the one staff-initiated write, for front desk registering a walk-in
 * (`guests.manage`). Not hotel-scoped: a Guest may hold reservations across
 * multiple hotels, so `guests.view`/`guests.manage` alone gate it; the
 * reservations sub-list is still filtered through the caller's own hotel
 * access.
 */
class GuestController extends Controller
{
    public function __construct(private readonly GuestService $guests) {}

    public function index(IndexGuestRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Guest::class);

        return $this->success(GuestResource::collection(
            $this->guests->list($request->filters(), $request->perPage())
        ));
    }

    /**
     * POST /api/v1/guests — register a walk-in guest (front desk, no app
     * account yet). No OTP: the phone stays unverified until its owner
     * verifies it themselves in the guest app.
     */
    public function store(StoreGuestRequest $request): JsonResponse
    {
        $this->authorize('create', Guest::class);

        $guest = $this->guests->create($request->guestData());

        return $this->success(new GuestResource($guest), __('api.created'), 201);
    }

    public function show(Request $request, Guest $guest): JsonResponse
    {
        $this->authorize('view', $guest);

        return $this->success(new GuestResource($guest->load('loyaltyAccount')));
    }

    public function reservations(Request $request, Guest $guest): JsonResponse
    {
        $this->authorize('view', $guest);

        $perPage = (int) $request->query('per_page', 15);

        return $this->success(ReservationResource::collection(
            $this->guests->reservationsFor($request->user(), $guest, $perPage)
        ));
    }
}
