<?php

namespace App\Http\Resources\V1;

use App\Domain\HotelGroup\Models\HotelMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HotelMedia
 *
 * Safe representation of a stored hotel image — id, collection, ordering and
 * the resolved public URL. The disk-relative path and disk name are internal
 * and never exposed.
 */
class HotelMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'collection' => $this->collection,
            'url' => $this->url(),
            'sort_order' => $this->sort_order,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'created_at' => $this->created_at,
        ];
    }
}
