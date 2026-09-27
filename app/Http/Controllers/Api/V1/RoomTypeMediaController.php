<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomMedia;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Services\RoomMediaService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RoomType\ReorderRoomTypeMediaRequest;
use App\Http\Requests\Api\V1\RoomType\StoreRoomTypeMediaRequest;
use App\Http\Resources\V1\RoomMediaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff room-type-media surface
 * (`/api/v1/hotels/{hotel}/room-types/{roomType}/media`). Thin: it
 * resolves + authorizes the room type (route-model bound,
 * `inventory.manage` + hotel scope via RoomTypePolicy::manageMedia) and
 * delegates every file/order rule to RoomMediaService. This is a
 * Staff/Dashboard endpoint — the guest app never calls it.
 */
class RoomTypeMediaController extends Controller
{
    public function __construct(private readonly RoomMediaService $media) {}

    public function store(StoreRoomTypeMediaRequest $request, Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $this->ensureBelongsToHotel($roomType, $hotel);
        $this->authorize('manageMedia', $roomType);

        $media = $this->media->upload(
            $roomType,
            $request->collection(),
            $request->file('image'),
            $request->user(),
        );

        return $this->success(new RoomMediaResource($media), __('api.created'), 201);
    }

    public function destroy(Request $request, Hotel $hotel, RoomType $roomType, RoomMedia $media): JsonResponse
    {
        $this->ensureBelongsToHotel($roomType, $hotel);
        $this->authorize('manageMedia', $roomType);

        if ($media->mediable_type !== RoomType::class || $media->mediable_id !== $roomType->id) {
            abort(404);
        }

        $this->media->delete($roomType, $media, $request->user());

        return $this->success(null, __('api.deleted'));
    }

    public function reorder(ReorderRoomTypeMediaRequest $request, Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $this->ensureBelongsToHotel($roomType, $hotel);
        $this->authorize('manageMedia', $roomType);

        $this->media->reorderGallery($roomType, $request->orderedIds(), $request->user());

        return $this->success(
            RoomMediaResource::collection($roomType->galleryMedia()->get()),
            __('api.updated'),
        );
    }

    /**
     * A Room Type belonging to another hotel must never be reachable
     * through this hotel's URL — mirrors
     * RoomTypeController::ensureBelongsToHotel().
     */
    private function ensureBelongsToHotel(RoomType $roomType, Hotel $hotel): void
    {
        if ($roomType->hotel_id !== $hotel->id) {
            abort(404);
        }
    }
}
