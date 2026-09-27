<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Services\HotelService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Hotel\IndexHotelRequest;
use App\Http\Requests\Api\V1\Hotel\StoreHotelRequest;
use App\Http\Requests\Api\V1\Hotel\UpdateHotelRequest;
use App\Http\Resources\V1\HotelResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    public function __construct(private readonly HotelService $hotels) {}

    /**
     * Hotels visible here are always resolved from the authenticated
     * user's own hotel access (or Group Owner bypass) — a client cannot
     * widen this by passing any request parameter. `search`/`is_active`/
     * `sort` are real server-side filters (IndexHotelRequest), applied on
     * top of that scope.
     */
    public function index(IndexHotelRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Hotel::class);

        return $this->success(HotelResource::collection(
            $this->hotels->listAccessibleBy($request->user(), $request->filters(), $request->perPage())
        ));
    }

    public function store(StoreHotelRequest $request): JsonResponse
    {
        $this->authorize('create', Hotel::class);

        $hotel = $this->hotels->create($request->validated(), $request->user());

        return $this->success(new HotelResource($hotel), __('api.created'), 201);
    }

    public function show(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('view', $hotel);

        $hotel->loadCount('reservations');
        $hotel->load(['hotelGroup', 'countryRef', 'cityRef', 'logo', 'cover', 'galleryMedia', 'facilities', 'highlights', 'nearbyPlaces']);

        return $this->success(new HotelResource($hotel));
    }

    public function update(UpdateHotelRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('update', $hotel);

        $hotel = $this->hotels->update($hotel, $request->validated(), $request->user());

        return $this->success(new HotelResource($hotel), __('api.updated'));
    }

    public function destroy(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('delete', $hotel);

        $this->hotels->delete($hotel, $request->user());

        return $this->success(null, __('api.deleted'));
    }
}
