<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use App\Domain\Location\Services\CityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\City\IndexCityRequest;
use App\Http\Requests\Api\V1\City\StoreCityRequest;
use App\Http\Requests\Api\V1\City\UpdateCityRequest;
use App\Http\Resources\V1\CityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function __construct(private readonly CityService $cities) {}

    public function index(IndexCityRequest $request): JsonResponse
    {
        $this->authorize('viewAny', City::class);

        return $this->success(CityResource::collection(
            $this->cities->list($request->filters(), $request->perPage())
        ));
    }

    /**
     * GET /countries/{country}/cities — the dependent-select lookup. Always
     * scoped to the route country; never leaks another country's cities.
     */
    public function forCountry(IndexCityRequest $request, Country $country): JsonResponse
    {
        $this->authorize('viewAny', City::class);

        $filters = $request->filters();

        return $this->success(CityResource::collection(
            $this->cities->listForCountry($country, [
                'search' => $filters['search'],
                'is_active' => $filters['is_active'],
            ], (int) $request->query('per_page', 50))
        ));
    }

    public function store(StoreCityRequest $request): JsonResponse
    {
        $this->authorize('create', City::class);

        $city = $this->cities->create($request->validated(), $request->user());

        return $this->success(new CityResource($city), __('api.created'), 201);
    }

    public function show(City $city): JsonResponse
    {
        $this->authorize('view', $city);

        $city->load('country:id,name_en,name_ar');

        return $this->success(new CityResource($city));
    }

    public function update(UpdateCityRequest $request, City $city): JsonResponse
    {
        $this->authorize('update', $city);

        $city = $this->cities->update($city, $request->validated(), $request->user());

        return $this->success(new CityResource($city), __('api.updated'));
    }

    public function activate(Request $request, City $city): JsonResponse
    {
        $this->authorize('update', $city);

        $city = $this->cities->activate($city, $request->user());

        return $this->success(new CityResource($city), __('api.updated'));
    }

    public function deactivate(Request $request, City $city): JsonResponse
    {
        $this->authorize('update', $city);

        $city = $this->cities->deactivate($city, $request->user());

        return $this->success(new CityResource($city), __('api.updated'));
    }

    public function destroy(Request $request, City $city): JsonResponse
    {
        $this->authorize('delete', $city);

        $this->cities->delete($city, $request->user());

        return $this->success(null, __('api.deleted'));
    }
}
