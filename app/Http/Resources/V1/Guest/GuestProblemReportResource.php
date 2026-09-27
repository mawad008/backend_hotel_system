<?php

namespace App\Http\Resources\V1\Guest;

use App\Domain\Support\Models\ProblemReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProblemReport
 *
 * The guest's own view of a submitted problem report — no staff/internal
 * bookkeeping (`hotel_id`, `resolved_by_user_id`).
 */
class GuestProblemReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'category' => $this->category,
            'urgency' => $this->urgency,
            'notes' => $this->notes,
            'status' => $this->status,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
        ];
    }
}
