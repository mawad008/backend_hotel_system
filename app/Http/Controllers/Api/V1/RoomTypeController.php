<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Services\RoomTypeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RoomType\StoreRoomTypeRequest;
use App\Http\Requests\Api\V1\RoomType\UpdateRoomTypeRequest;
use App\Http\Resources\V1\RoomTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    public function __construct(private readonly RoomTypeService $roomTypes) {}

    /**
     * Room Types visible here are always resolved from $hotel (the
     * authorized route context) — a client cannot widen this by passing
     * any request parameter.
     */
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewAny', [RoomType::class, $hotel]);

        $roomTypes = $this->roomTypes->listForHotel($request->user(), $hotel);
        $roomTypes->getCollection()->load('media');

        return $this->success(RoomTypeResource::collection($roomTypes));
    }

    public function store(StoreRoomTypeRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('create', [RoomType::class, $hotel]);

        $roomType = $this->roomTypes->create($hotel, $request->validated(), $request->user());

        return $this->success(new RoomTypeResource($roomType), __('api.created'), 201);
    }

    public function show(Request $request, Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $this->ensureBelongsToHotel($roomType, $hotel);
        $this->authorize('view', $roomType);

        $found = $this->roomTypes->find($roomType->id);
        $found?->load('media');

        return $this->success(new RoomTypeResource($found));
    }

    public function update(UpdateRoomTypeRequest $request, Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $this->ensureBelongsToHotel($roomType, $hotel);
        $this->authorize('update', $roomType);

        $roomType = $this->roomTypes->update($roomType, $request->validated(), $request->user());

        return $this->success(new RoomTypeResource($roomType), __('api.updated'));
    }

    public function activate(Request $request, Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $this->ensureBelongsToHotel($roomType, $hotel);
        $this->authorize('update', $roomType);

        $roomType = $this->roomTypes->activate($roomType, $request->user());

        return $this->success(new RoomTypeResource($roomType), __('api.updated'));
    }

    public function deactivate(Request $request, Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $this->ensureBelongsToHotel($roomType, $hotel);
        $this->authorize('update', $roomType);

        $roomType = $this->roomTypes->deactivate($roomType, $request->user());

        return $this->success(new RoomTypeResource($roomType), __('api.updated'));
    }

    /**
     * A Room Type belonging to another hotel must never be reachable
     * through this hotel's URL — this is a pure route/tenancy-boundary
     * check on already route-bound models (no extra query), independent
     * of whether the user could otherwise access that other hotel.
     */
    private function ensureBelongsToHotel(RoomType $roomType, Hotel $hotel): void
    {
        if ($roomType->hotel_id !== $hotel->id) {
            abort(404);
        }
    }
}
