<?php

namespace App\Http\Resources\V1;

use App\Domain\Support\Models\ProblemReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProblemReport
 *
 * Staff-facing — the full record, including the reporting guest/hotel and
 * resolution bookkeeping.
 */
class ProblemReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'guest_id' => $this->guest_id,
            'hotel_id' => $this->hotel_id,
            'category' => $this->category,
            'urgency' => $this->urgency,
            'notes' => $this->notes,
            'status' => $this->status,
            'resolved_by_user_id' => $this->resolved_by_user_id,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
