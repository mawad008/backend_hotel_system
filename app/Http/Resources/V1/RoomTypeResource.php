<?php

namespace App\Http\Resources\V1;

use App\Domain\Inventory\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoomType
 */
class RoomTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'name' => $this->name,
            'base_price' => $this->base_price,
            'capacity' => $this->capacity,
            'amenities' => $this->amenities,
            'description' => $this->description,
            'bed_type_i18n' => $this->bed_type_i18n,
            'view_i18n' => $this->view_i18n,
            'area_sqm' => $this->area_sqm,
            'breakfast_included' => (bool) $this->breakfast_included,
            'refundable' => (bool) $this->refundable,
            'custom_specs' => $this->custom_specs ?? [],
            'tag_i18n' => $this->tag_i18n,
            'inclusions_i18n' => $this->inclusions_i18n ?? [],
            'is_active' => $this->is_active,
            'photos' => RoomMediaResource::collection($this->whenLoaded('media')),
            'rooms_count' => $this->whenCounted('rooms'),
            'available_rooms_count' => $this->whenCounted('available_rooms'),
            'maintenance_rooms_count' => $this->whenCounted('maintenance_rooms'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
