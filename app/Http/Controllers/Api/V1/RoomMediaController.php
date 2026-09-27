<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomMedia;
use App\Domain\Inventory\Services\RoomMediaService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Room\ReorderRoomMediaRequest;
use App\Http\Requests\Api\V1\Room\StoreRoomMediaRequest;
use App\Http\Resources\V1\RoomMediaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff room-media surface (`/api/v1/hotels/{hotel}/rooms/{room}/media`).
 * Thin: it resolves + authorizes the room (route-model bound,
 * `inventory.manage` + hotel scope via RoomPolicy::manageMedia) and
 * delegates every file/order rule to RoomMediaService. This is a
 * Staff/Dashboard endpoint — the guest app never calls it.
 */
class RoomMediaController extends Controller
{
    public function __construct(private readonly RoomMediaService $media) {}

    public function store(StoreRoomMediaRequest $request, Hotel $hotel, Room $room): JsonResponse
    {
        $this->ensureBelongsToHotel($room, $hotel);
        $this->authorize('manageMedia', $room);

        $media = $this->media->upload(
            $room,
            $request->collection(),
            $request->file('image'),
            $request->user(),
        );

        return $this->success(new RoomMediaResource($media), __('api.created'), 201);
    }

    public function destroy(Request $request, Hotel $hotel, Room $room, RoomMedia $media): JsonResponse
    {
        $this->ensureBelongsToHotel($room, $hotel);
        $this->authorize('manageMedia', $room);

        if ($media->mediable_type !== Room::class || $media->mediable_id !== $room->id) {
            abort(404);
        }

        $this->media->delete($room, $media, $request->user());

        return $this->success(null, __('api.deleted'));
    }

    public function reorder(ReorderRoomMediaRequest $request, Hotel $hotel, Room $room): JsonResponse
    {
        $this->ensureBelongsToHotel($room, $hotel);
        $this->authorize('manageMedia', $room);

        $this->media->reorderGallery($room, $request->orderedIds(), $request->user());

        return $this->success(
            RoomMediaResource::collection($room->galleryMedia()->get()),
            __('api.updated'),
        );
    }

    /**
     * A Room belonging to another hotel must never be reachable through
     * this hotel's URL — mirrors RoomController::ensureBelongsToHotel().
     */
    private function ensureBelongsToHotel(Room $room, Hotel $hotel): void
    {
        if ($room->hotel_id !== $hotel->id) {
            abort(404);
        }
    }
}
