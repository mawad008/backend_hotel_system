<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\Support\Models\ProblemReport;
use App\Domain\Support\Services\ProblemReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Guest\SubmitProblemReportRequest;
use App\Http\Resources\V1\Guest\GuestProblemReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own problem reports for one of their
 * reservations (`/api/v1/guest/reservations/{reservation}/problems`) —
 * mobile/Design/13 · Report a problem.png. Dedicated GUEST controller
 * reusing ProblemReportService unchanged. Ownership resolves from the
 * token; category/urgency/notes are the only client-supplied fields.
 */
class GuestProblemReportController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ProblemReportService $reports,
    ) {}

    public function index(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $reports = $this->reports->forReservation($found->id, (int) $request->query('per_page', 15));

        return $this->success(GuestProblemReportResource::collection($reports), __('api.problem_reports.list'));
    }

    public function show(Request $request, int $reservation, int $problem): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $report = ProblemReport::query()
            ->where('reservation_id', $found->id)
            ->find($problem);

        if (! $report) {
            abort(404);
        }

        return $this->success(new GuestProblemReportResource($report), __('api.problem_reports.status'));
    }

    public function store(SubmitProblemReportRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $report = $this->reports->submitForReservation(
            $found, $request->category(), $request->urgency(), $request->notes(),
        );

        return $this->success(
            new GuestProblemReportResource($report), __('api.problem_reports.submitted'), 201,
        );
    }

    private function reservationFor(Request $request, int $reservation): Reservation
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        return $found;
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
