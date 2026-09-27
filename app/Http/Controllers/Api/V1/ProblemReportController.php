<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Support\Models\ProblemReport;
use App\Domain\Support\Services\ProblemReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProblemReport\TransitionProblemReportStatusRequest;
use App\Http\Resources\V1\ProblemReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff-facing listing (every status) + triage decision for guest problem
 * reports. Separate from the guest surface — a guest never reaches this
 * controller (GuestProblemReportController is the only guest-facing entry
 * point).
 */
class ProblemReportController extends Controller
{
    public function __construct(private readonly ProblemReportService $reports) {}

    public function index(Request $request, int $hotel): JsonResponse
    {
        $found = Hotel::query()->find($hotel);

        if (! $found) {
            abort(404);
        }

        $this->authorize('view', [ProblemReport::class, $found]);

        $status = $request->query('status');

        $reports = $this->reports->forHotel(
            $found->id,
            is_string($status) ? $status : null,
            (int) $request->query('per_page', 15),
        );

        return $this->success(ProblemReportResource::collection($reports), __('api.problem_reports.list'));
    }

    public function transitionStatus(TransitionProblemReportStatusRequest $request, int $problem): JsonResponse
    {
        $found = ProblemReport::query()->find($problem);

        if (! $found) {
            abort(404);
        }

        $this->authorize('manage', $found);

        $updated = $this->reports->transitionStatus($found, $request->status(), $request->user());

        return $this->success(new ProblemReportResource($updated), __('api.problem_reports.'.$updated->status));
    }
}
