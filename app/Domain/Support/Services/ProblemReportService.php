<?php

namespace App\Domain\Support\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Support\Exceptions\InvalidProblemReportStatusTransitionException;
use App\Domain\Support\Models\ProblemReport;
use App\Domain\Support\Repositories\Contracts\ProblemReportRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * In-stay problem-report workflow (mobile/Design/13 · Report a problem.png).
 *
 * ── Invariants ──
 * - The client never sets guest_id or hotel_id — both are server-derived
 *   from the reservation. Category/urgency are validated against the fixed
 *   catalog (ProblemReport::CATEGORIES / ::URGENCIES) by the caller's
 *   FormRequest before this is ever called.
 * - Status only ever moves forward: open -> in_progress -> resolved, or
 *   open -> resolved directly for a quick close. No reopening, no reject —
 *   this is operational triage, not a moderation decision (unlike Review).
 */
class ProblemReportService
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        ProblemReport::STATUS_OPEN => [ProblemReport::STATUS_IN_PROGRESS, ProblemReport::STATUS_RESOLVED],
        ProblemReport::STATUS_IN_PROGRESS => [ProblemReport::STATUS_RESOLVED],
        ProblemReport::STATUS_RESOLVED => [],
    ];

    public function __construct(
        private readonly ProblemReportRepositoryInterface $reports,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function forReservation(int $reservationId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reports->paginateForReservation($reservationId, $perPage);
    }

    public function forHotel(int $hotelId, ?string $status, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reports->paginateForHotel($hotelId, $status, $perPage);
    }

    public function submitForReservation(
        Reservation $reservation,
        string $category,
        string $urgency,
        ?string $notes,
    ): ProblemReport {
        $report = $this->reports->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $reservation->guest_id,
            'hotel_id' => $reservation->hotel_id,
            'category' => $category,
            'urgency' => $urgency,
            'notes' => $notes,
            'status' => ProblemReport::STATUS_OPEN,
        ]);

        $this->auditLogger->record(
            null, 'problem_report.submitted', $report,
            after: ['category' => $report->category, 'urgency' => $report->urgency],
            hotelId: $reservation->hotel_id,
        );

        return $report;
    }

    /**
     * @throws InvalidProblemReportStatusTransitionException
     */
    public function transitionStatus(ProblemReport $report, string $status, ?User $actor = null): ProblemReport
    {
        if (! in_array($status, self::ALLOWED_TRANSITIONS[$report->status] ?? [], true)) {
            throw InvalidProblemReportStatusTransitionException::from($report->status, $status);
        }

        $updated = $this->reports->update($report, [
            'status' => $status,
            'resolved_by_user_id' => $status === ProblemReport::STATUS_RESOLVED ? $actor?->id : $report->resolved_by_user_id,
            'resolved_at' => $status === ProblemReport::STATUS_RESOLVED ? now() : $report->resolved_at,
        ]);

        $this->auditLogger->record(
            $actor, 'problem_report.status_changed', $updated,
            after: ['status' => $updated->status],
            hotelId: $updated->hotel_id,
        );

        return $updated;
    }
}
