<?php

namespace App\Http\Resources\V1;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Review\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Review
 *
 * Guest-facing baseline exactly matches the contract documented in
 * mobile/docs/mobile-phase-10-loyalty-reviews.md ("review_models.dart
 * mirrors the expected future ReviewResource"): id, reservation_id, rating,
 * text, status, created_at. Moderation bookkeeping (`hotel_id`,
 * `moderated_at`) is staff-only — a guest never sees who/when moderated
 * their review.
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isStaff = $request->user() instanceof User;

        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'rating' => $this->rating,
            'text' => $this->text,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'category_ratings' => ReviewCategoryRatingResource::collection($this->whenLoaded('categoryRatings')),
            'hotel_id' => $this->when($isStaff, $this->hotel_id),
            'guest_id' => $this->when($isStaff, $this->guest_id),
            'guest' => $this->when($isStaff && $this->relationLoaded('guest'), fn () => [
                'id' => $this->guest?->id,
                'name' => $this->guest?->name,
            ]),
            'hotel' => $this->when($isStaff && $this->relationLoaded('hotel'), fn () => [
                'id' => $this->hotel?->id,
                'name' => $this->hotel?->name,
            ]),
            'moderated_at' => $this->when($isStaff, $this->moderated_at),
        ];
    }
}
