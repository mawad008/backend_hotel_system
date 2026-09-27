<?php

namespace App\Http\Resources\V1;

use App\Domain\Location\Models\City;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin City
 *
 * The `country` summary is a flat {id, name_en, name_ar} — deliberately not
 * a nested CountryResource, to avoid circular / oversized payloads.
 */
class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_id' => $this->country_id,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'is_active' => $this->is_active,
            'country' => $this->whenLoaded('country', fn () => [
                'id' => $this->country->id,
                'name_en' => $this->country->name_en,
                'name_ar' => $this->country->name_ar,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
