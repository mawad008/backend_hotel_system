<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Services\RoomService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Room\StoreRoomRequest;
use App\Http\Requests\Api\V1\Room\UpdateRoomRequest;
use App\Http\Requests\Api\V1\Room\UpdateRoomStatusRequest;
use App\Http\Resources\V1\RoomResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function __construct(private readonly RoomService $rooms) {}

    /**
     * Rooms visible here are always resolved from $hotel (the authorized
     * route context) — a client cannot widen this by passing any request
     * parameter. room_type_id, if present, only narrows the list.
     */
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewAny', [Room::class, $hotel]);

        $roomTypeId = $request->filled('room_type_id') ? (int) $request->query('room_type_id') : null;

        $rooms = $this->rooms->listForHotel($request->user(), $hotel, $roomTypeId);
        $rooms->getCollection()->load('media');

        return $this->success(RoomResource::collection($rooms));
    }

    public function store(StoreRoomRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('create', [Room::class, $hotel]);

        $room = $this->rooms->create($hotel, $request->validated(), $request->user());

        return $this->success(new RoomResource($room), __('api.created'), 201);
    }

    public function show(Request $request, Hotel $hotel, Room $room): JsonResponse
    {
        $this->ensureBelongsToHotel($room, $hotel);
        $this->authorize('view', $room);

        $room->load('media');

        return $this->success(new RoomResource($room));
    }

    public function update(UpdateRoomRequest $request, Hotel $hotel, Room $room): JsonResponse
    {
        $this->ensureBelongsToHotel($room, $hotel);
        $this->authorize('update', $room);

        $room = $this->rooms->update($room, $request->validated(), $request->user());

        return $this->success(new RoomResource($room), __('api.updated'));
    }

    /**
     * The only path that may change a Room's status — always routed
     * through RoomService::transitionStatus(), which locks the row,
     * re-reads the current state, and validates the transition before
     * writing. `booked` is never reachable here, in either direction.
     */
    public function updateStatus(UpdateRoomStatusRequest $request, Hotel $hotel, Room $room): JsonResponse
    {
        $this->ensureBelongsToHotel($room, $hotel);
        $this->authorize('update', $room);

        $room = $this->rooms->transitionStatus($room, $request->validated('status'), $request->user());

        return $this->success(new RoomResource($room), __('api.updated'));
    }

    /**
     * A Room belonging to another hotel must never be reachable through
     * this hotel's URL — this is a pure route/tenancy-boundary check on
     * already route-bound models (no extra query), independent of
     * whether the user could otherwise access that other hotel.
     */
    private function ensureBelongsToHotel(Room $room, Hotel $hotel): void
    {
        if ($room->hotel_id !== $hotel->id) {
            abort(404);
        }
    }
}
