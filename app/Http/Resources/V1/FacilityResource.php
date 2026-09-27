<?php

namespace App\Http\Resources\V1;

use App\Domain\HotelGroup\Models\Facility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Facility
 */
class FacilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name_i18n' => $this->name_i18n,
            'description_i18n' => $this->description_i18n,
            'icon' => $this->icon,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'hotels_count' => $this->whenCounted('hotels'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
