<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\StayServices\Models\ServiceReview;
use App\Domain\StayServices\Services\ServiceReviewService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ServiceReview\ModerateServiceReviewRequest;
use App\Http\Resources\V1\ServiceReviewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff-facing service-review listing (every moderation state) + moderation
 * decision. Mirrors ReviewController exactly. A guest never reaches this
 * controller — GuestServiceReviewController is the only guest-facing entry
 * point.
 */
class ServiceReviewController extends Controller
{
    public function __construct(private readonly ServiceReviewService $reviews) {}

    public function index(Request $request, int $hotel): JsonResponse
    {
        $found = Hotel::query()->find($hotel);

        if (! $found) {
            abort(404);
        }

        $this->authorize('view', [ServiceReview::class, $found]);

        $serviceId = $request->query('service_id');
        $status = $request->query('status');

        $reviews = $this->reviews->forHotel(
            $found->id,
            is_numeric($serviceId) ? (int) $serviceId : null,
            is_string($status) ? $status : null,
            (int) $request->query('per_page', 15),
        );

        return $this->success(ServiceReviewResource::collection($reviews));
    }

    public function moderate(ModerateServiceReviewRequest $request, int $serviceReview): JsonResponse
    {
        $found = ServiceReview::query()->find($serviceReview);

        if (! $found) {
            abort(404);
        }

        $this->authorize('moderate', $found);

        $updated = $this->reviews->moderate($found, $request->decision(), $request->user());

        return $this->success(new ServiceReviewResource($updated));
    }
}
