<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\StayServices\Models\ServiceCategory;
use App\Domain\StayServices\Services\ServiceCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ServiceCategory\StoreServiceCategoryRequest;
use App\Http\Requests\Api\V1\ServiceCategory\UpdateServiceCategoryRequest;
use App\Http\Resources\V1\ServiceCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8 — the hotel service-category catalog surface (Phase 0 §16:
 * /api/v1/hotels/{hotel}/service-categories). Thin: authorize against the
 * route Hotel, delegate to ServiceCatalogService.
 */
class ServiceCategoryController extends Controller
{
    public function __construct(private readonly ServiceCatalogService $catalog) {}

    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewAny', [ServiceCategory::class, $hotel]);

        return $this->success(ServiceCategoryResource::collection(
            $this->catalog->listCategories($request->user(), $hotel)
        ));
    }

    public function store(StoreServiceCategoryRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('create', [ServiceCategory::class, $hotel]);

        $category = $this->catalog->createCategory($hotel, $request->validated(), $request->user());

        return $this->success(new ServiceCategoryResource($category), __('api.created'), 201);
    }

    public function update(UpdateServiceCategoryRequest $request, Hotel $hotel, ServiceCategory $serviceCategory): JsonResponse
    {
        $this->ensureBelongsToHotel($serviceCategory, $hotel);
        $this->authorize('update', $serviceCategory);

        $category = $this->catalog->updateCategory($serviceCategory, $request->validated(), $request->user());

        return $this->success(new ServiceCategoryResource($category), __('api.updated'));
    }

    public function activate(Request $request, Hotel $hotel, ServiceCategory $serviceCategory): JsonResponse
    {
        $this->ensureBelongsToHotel($serviceCategory, $hotel);
        $this->authorize('update', $serviceCategory);

        $category = $this->catalog->activateCategory($serviceCategory, $request->user());

        return $this->success(new ServiceCategoryResource($category), __('api.updated'));
    }

    public function deactivate(Request $request, Hotel $hotel, ServiceCategory $serviceCategory): JsonResponse
    {
        $this->ensureBelongsToHotel($serviceCategory, $hotel);
        $this->authorize('update', $serviceCategory);

        $category = $this->catalog->deactivateCategory($serviceCategory, $request->user());

        return $this->success(new ServiceCategoryResource($category), __('api.updated'));
    }

    /**
     * A category belonging to another hotel is never reachable through this
     * hotel's URL — a pure route/tenancy check on the route-bound models.
     */
    private function ensureBelongsToHotel(ServiceCategory $category, Hotel $hotel): void
    {
        if ($category->hotel_id !== $hotel->id) {
            abort(404);
        }
    }
}
