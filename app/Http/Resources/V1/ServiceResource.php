<?php

namespace App\Http\Resources\V1;

use App\Domain\StayServices\Models\HotelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HotelService
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'service_category_id' => $this->service_category_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            // Real, per-service aggregate from published service reviews
            // only (see EloquentHotelServiceRepository, always loaded for
            // every call site this resource serves today). `null` — never a
            // fabricated `0`/`5` — when the service has no published
            // reviews yet; `reviews_count` is then genuinely `0`.
            'rating' => $this->when(
                ($this->avg_rating ?? null) !== null,
                fn () => number_format((float) $this->avg_rating, 2, '.', ''),
            ),
            'reviews_count' => $this->reviews_count ?? 0,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
