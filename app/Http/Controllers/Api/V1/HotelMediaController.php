<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelMedia;
use App\Domain\HotelGroup\Services\HotelMediaService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Hotel\ReorderHotelMediaRequest;
use App\Http\Requests\Api\V1\Hotel\StoreHotelMediaRequest;
use App\Http\Resources\V1\HotelMediaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff hotel-media surface (`/api/v1/hotels/{hotel}/media`). Thin: it
 * resolves + authorizes the hotel (route-model bound, `hotels.manage` +
 * hotel scope via HotelPolicy::manageMedia) and delegates every file/order
 * rule to HotelMediaService. This is a Staff/Dashboard endpoint — the guest
 * app never calls it; guests only read the resolved URLs on the public
 * hotel resource.
 */
class HotelMediaController extends Controller
{
    public function __construct(private readonly HotelMediaService $media) {}

    public function store(StoreHotelMediaRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('manageMedia', $hotel);

        $media = $this->media->upload(
            $hotel,
            $request->collection(),
            $request->file('image'),
            $request->user(),
        );

        return $this->success(new HotelMediaResource($media), __('api.created'), 201);
    }

    public function destroy(Request $request, Hotel $hotel, HotelMedia $media): JsonResponse
    {
        $this->authorize('manageMedia', $hotel);

        if ($media->hotel_id !== $hotel->id) {
            abort(404);
        }

        $this->media->delete($hotel, $media, $request->user());

        return $this->success(null, __('api.deleted'));
    }

    public function reorder(ReorderHotelMediaRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('manageMedia', $hotel);

        $this->media->reorderGallery($hotel, $request->orderedIds(), $request->user());

        return $this->success(
            HotelMediaResource::collection($hotel->galleryMedia()->get()),
            __('api.updated'),
        );
    }
}
