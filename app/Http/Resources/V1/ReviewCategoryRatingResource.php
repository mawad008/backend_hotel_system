<?php

namespace App\Http\Resources\V1;

use App\Domain\Review\Models\ReviewCategoryRating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One per-category rating inside a review. `label` comes from the snapshot
 * taken when the guest rated it — so a later rename never rewrites what the
 * guest rated; `category_id` links it to the live category.
 *
 * @mixin ReviewCategoryRating
 */
class ReviewCategoryRatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'category_id' => $this->review_category_id,
            'label' => $this->localizedSnapshotName(),
            'name' => $this->category_name,
            'name_ar' => $this->category_name_ar,
            'name_en' => $this->category_name_en,
            'rating' => $this->rating,
        ];
    }
}
