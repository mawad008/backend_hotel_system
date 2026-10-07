<?php

namespace App\Http\Resources\V1;

use App\Domain\Notification\Models\StaffNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffNotification
 *
 * The dashboard renders the localized title/body from `type`; this carries
 * only identifiers and the hotel name for the viewer's language.
 */
class StaffNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'needs_attention' => $this->type->needsAttention(),
            'hotel_id' => $this->hotel_id,
            'hotel_name' => $this->whenLoaded('hotel', fn () => $this->hotel?->name),
            'hotel_name_i18n' => $this->whenLoaded('hotel', fn () => $this->hotel?->name_i18n),
            'reservation_id' => $this->reservation_id,
            'subject_id' => $this->context['subject_id'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
