<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Services\ServiceCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Service\StoreServiceRequest;
use App\Http\Requests\Api\V1\Service\UpdateServiceRequest;
use App\Http\Resources\V1\ServiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8 — the hotel service catalog surface (Phase 0 §16:
 * /api/v1/hotels/{hotel}/services). Thin: authorize against the route
 * Hotel, delegate to ServiceCatalogService. No delete endpoint — a service
 * with historical orders is deactivated, never removed.
 */
class ServiceController extends Controller
{
    public function __construct(private readonly ServiceCatalogService $catalog) {}

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewAny', [HotelService::class, $hotel]);

        $onlyActive = $request->has('active') ? $request->boolean('active') : null;

        return $this->success(ServiceResource::collection(
            $this->catalog->listServices($request->user(), $hotel, $onlyActive)
        ));
    }

    public function store(StoreServiceRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('create', [HotelService::class, $hotel]);

        $service = $this->catalog->createService($hotel, $request->validated(), $request->user());

        return $this->success(new ServiceResource($service), __('api.created'), 201);
    }

    public function show(Request $request, Hotel $hotel, HotelService $service): JsonResponse
    {
        $this->ensureBelongsToHotel($service, $hotel);
        $this->authorize('view', $service);

        return $this->success(new ServiceResource($service));
    }

    public function update(UpdateServiceRequest $request, Hotel $hotel, HotelService $service): JsonResponse
    {
        $this->ensureBelongsToHotel($service, $hotel);
        $this->authorize('update', $service);

        $service = $this->catalog->updateService($service, $request->validated(), $request->user());

        return $this->success(new ServiceResource($service), __('api.updated'));
    }

    public function activate(Request $request, Hotel $hotel, HotelService $service): JsonResponse
    {
        $this->ensureBelongsToHotel($service, $hotel);
        $this->authorize('update', $service);

        $service = $this->catalog->activateService($service, $request->user());

        return $this->success(new ServiceResource($service), __('api.updated'));
    }

    public function deactivate(Request $request, Hotel $hotel, HotelService $service): JsonResponse
    {
        $this->ensureBelongsToHotel($service, $hotel);
        $this->authorize('update', $service);

        $service = $this->catalog->deactivateService($service, $request->user());

        return $this->success(new ServiceResource($service), __('api.updated'));
    }

    private function ensureBelongsToHotel(HotelService $service, Hotel $hotel): void
    {
        if ($service->hotel_id !== $hotel->id) {
            abort(404);
        }
    }
}
