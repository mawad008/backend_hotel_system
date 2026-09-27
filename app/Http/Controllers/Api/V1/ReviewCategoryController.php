<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Review\Models\ReviewCategory;
use App\Domain\Review\Services\ReviewCategoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewCategory\ReorderReviewCategoriesRequest;
use App\Http\Requests\Api\V1\ReviewCategory\StoreReviewCategoryRequest;
use App\Http\Requests\Api\V1\ReviewCategory\UpdateReviewCategoryRequest;
use App\Http\Resources\V1\ReviewCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard management of a hotel's dynamic review categories. Same
 * hotel-scoped shape as the service-category endpoints; deletion is allowed
 * only for a category no guest has rated yet (else deactivate).
 */
class ReviewCategoryController extends Controller
{
    public function __construct(private readonly ReviewCategoryService $categories) {}

    public function index(Hotel $hotel): JsonResponse
    {
        $this->authorize('viewAny', [ReviewCategory::class, $hotel]);

        return $this->success(ReviewCategoryResource::collection($this->categories->forHotel($hotel)));
    }

    public function store(StoreReviewCategoryRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('create', [ReviewCategory::class, $hotel]);
        $category = $this->categories->create($hotel, $request->validated(), $request->user());

        return $this->success(new ReviewCategoryResource($category), __('api.created'), 201);
    }

    public function update(UpdateReviewCategoryRequest $request, Hotel $hotel, ReviewCategory $reviewCategory): JsonResponse
    {
        $this->ensureBelongsToHotel($reviewCategory, $hotel);
        $this->authorize('update', $reviewCategory);
        $category = $this->categories->update($reviewCategory, $request->validated(), $request->user());

        return $this->success(new ReviewCategoryResource($category), __('api.updated'));
    }

    public function activate(Request $request, Hotel $hotel, ReviewCategory $reviewCategory): JsonResponse
    {
        return $this->setActive($request, $hotel, $reviewCategory, true);
    }

    public function deactivate(Request $request, Hotel $hotel, ReviewCategory $reviewCategory): JsonResponse
    {
        return $this->setActive($request, $hotel, $reviewCategory, false);
    }

    public function reorder(ReorderReviewCategoriesRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('create', [ReviewCategory::class, $hotel]);
        $ordered = $this->categories->reorder($hotel, $request->ids(), $request->user());

        return $this->success(ReviewCategoryResource::collection($ordered), __('api.updated'));
    }

    public function destroy(Request $request, Hotel $hotel, ReviewCategory $reviewCategory): JsonResponse
    {
        $this->ensureBelongsToHotel($reviewCategory, $hotel);
        $this->authorize('delete', $reviewCategory);
        $this->categories->delete($reviewCategory, $request->user());

        return $this->success(null, __('api.deleted'));
    }

    private function setActive(Request $request, Hotel $hotel, ReviewCategory $category, bool $active): JsonResponse
    {
        $this->ensureBelongsToHotel($category, $hotel);
        $this->authorize('update', $category);

        return $this->success(
            new ReviewCategoryResource($this->categories->setActive($category, $active, $request->user())),
            __('api.updated'),
        );
    }

    private function ensureBelongsToHotel(ReviewCategory $category, Hotel $hotel): void
    {
        if ($category->hotel_id !== $hotel->id) {
            abort(404);
        }
    }
}
