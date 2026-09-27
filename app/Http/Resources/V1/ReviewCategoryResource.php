<?php

namespace App\Http\Resources\V1;

use App\Domain\Review\Models\ReviewCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dynamic review category. `label` is the request-locale display name
 * (`name_{locale}` → `name`); the raw fields are returned for editing.
 *
 * @mixin ReviewCategory
 */
class ReviewCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'label' => $this->localizedName(),
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $this->description,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
