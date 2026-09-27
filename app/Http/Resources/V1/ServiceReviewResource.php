<?php

namespace App\Http\Resources\V1;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\ServiceReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceReview
 *
 * Guest-facing baseline mirrors ReviewResource exactly: id,
 * service_order_id, rating, text, status, created_at. Moderation
 * bookkeeping (`hotel_id`, `service_id`, `guest_id`, `moderated_at`) is
 * staff-only.
 */
class ServiceReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isStaff = $request->user() instanceof User;

        return [
            'id' => $this->id,
            'service_order_id' => $this->service_order_id,
            'rating' => $this->rating,
            'text' => $this->text,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'hotel_id' => $this->when($isStaff, $this->hotel_id),
            'service_id' => $this->when($isStaff, $this->service_id),
            'guest_id' => $this->when($isStaff, $this->guest_id),
            'moderated_at' => $this->when($isStaff, $this->moderated_at),
        ];
    }
}
