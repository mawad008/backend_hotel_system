<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Services\FacilityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Facility\IndexFacilityRequest;
use App\Http\Requests\Api\V1\Facility\StoreFacilityRequest;
use App\Http\Requests\Api\V1\Facility\UpdateFacilityRequest;
use App\Http\Resources\V1\FacilityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The facility catalog — global reference data, not hotel-scoped.
 * Authorization is purely permission-driven (facilities.view /
 * facilities.manage). Thin: validate, authorize, delegate to
 * FacilityService.
 */
class FacilityController extends Controller
{
    public function __construct(private readonly FacilityService $facilities) {}

    public function index(IndexFacilityRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Facility::class);

        if ($request->boolean('all')) {
            return $this->success(FacilityResource::collection($this->facilities->pickerOptions()));
        }

        return $this->success(FacilityResource::collection(
            $this->facilities->list($request->filters(), $request->perPage())
        ));
    }

    public function store(StoreFacilityRequest $request): JsonResponse
    {
        $this->authorize('create', Facility::class);

        $facility = $this->facilities->create($request->validated(), $request->user());

        return $this->success(new FacilityResource($facility), __('api.created'), 201);
    }

    public function show(Facility $facility): JsonResponse
    {
        $this->authorize('view', $facility);

        return $this->success(new FacilityResource($facility));
    }

    public function update(UpdateFacilityRequest $request, Facility $facility): JsonResponse
    {
        $this->authorize('update', $facility);

        $facility = $this->facilities->update($facility, $request->validated(), $request->user());

        return $this->success(new FacilityResource($facility), __('api.updated'));
    }

    public function activate(Request $request, Facility $facility): JsonResponse
    {
        $this->authorize('update', $facility);

        $facility = $this->facilities->activate($facility, $request->user());

        return $this->success(new FacilityResource($facility), __('api.updated'));
    }

    public function deactivate(Request $request, Facility $facility): JsonResponse
    {
        $this->authorize('update', $facility);

        $facility = $this->facilities->deactivate($facility, $request->user());

        return $this->success(new FacilityResource($facility), __('api.updated'));
    }

    public function destroy(Request $request, Facility $facility): JsonResponse
    {
        $this->authorize('delete', $facility);

        $this->facilities->delete($facility, $request->user());

        return $this->success(null, __('api.deleted'));
    }
}
