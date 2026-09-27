<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\HotelGroup\Services\HotelGroupService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\HotelGroup\StoreHotelGroupRequest;
use App\Http\Requests\Api\V1\HotelGroup\UpdateHotelGroupRequest;
use App\Http\Resources\V1\HotelGroupResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HotelGroupController extends Controller
{
    public function __construct(private readonly HotelGroupService $hotelGroups) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HotelGroup::class);

        return $this->success(HotelGroupResource::collection($this->hotelGroups->list()));
    }

    public function store(StoreHotelGroupRequest $request): JsonResponse
    {
        $this->authorize('create', HotelGroup::class);

        $group = $this->hotelGroups->create($request->validated(), $request->user());

        return $this->success(new HotelGroupResource($group), __('api.created'), 201);
    }

    public function show(HotelGroup $hotel_group): JsonResponse
    {
        $this->authorize('view', $hotel_group);

        return $this->success(new HotelGroupResource($hotel_group));
    }

    public function update(UpdateHotelGroupRequest $request, HotelGroup $hotel_group): JsonResponse
    {
        $this->authorize('update', $hotel_group);

        $group = $this->hotelGroups->update($hotel_group, $request->validated(), $request->user());

        return $this->success(new HotelGroupResource($group), __('api.updated'));
    }
}
