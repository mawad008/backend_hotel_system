<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Location\Models\Country;
use App\Domain\Location\Services\CountryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Country\IndexCountryRequest;
use App\Http\Requests\Api\V1\Country\StoreCountryRequest;
use App\Http\Requests\Api\V1\Country\UpdateCountryRequest;
use App\Http\Resources\V1\CountryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global reference data — not hotel-scoped. Authorization is purely
 * permission-driven (locations.view / locations.manage). Thin: validate,
 * authorize, delegate to CountryService.
 */
class CountryController extends Controller
{
    public function __construct(private readonly CountryService $countries) {}

    public function index(IndexCountryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Country::class);

        return $this->success(CountryResource::collection(
            $this->countries->list($request->filters(), $request->perPage())
        ));
    }

    public function store(StoreCountryRequest $request): JsonResponse
    {
        $this->authorize('create', Country::class);

        $country = $this->countries->create($request->validated(), $request->user());

        return $this->success(new CountryResource($country), __('api.created'), 201);
    }

    public function show(Country $country): JsonResponse
    {
        $this->authorize('view', $country);

        $country->loadCount('cities');

        return $this->success(new CountryResource($country));
    }

    public function update(UpdateCountryRequest $request, Country $country): JsonResponse
    {
        $this->authorize('update', $country);

        $country = $this->countries->update($country, $request->validated(), $request->user());

        return $this->success(new CountryResource($country), __('api.updated'));
    }

    public function activate(Request $request, Country $country): JsonResponse
    {
        $this->authorize('update', $country);

        $country = $this->countries->activate($country, $request->user());

        return $this->success(new CountryResource($country), __('api.updated'));
    }

    public function deactivate(Request $request, Country $country): JsonResponse
    {
        $this->authorize('update', $country);

        $country = $this->countries->deactivate($country, $request->user());

        return $this->success(new CountryResource($country), __('api.updated'));
    }

    public function destroy(Request $request, Country $country): JsonResponse
    {
        $this->authorize('delete', $country);

        $this->countries->delete($country, $request->user());

        return $this->success(null, __('api.deleted'));
    }
}
