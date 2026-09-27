<?php

namespace App\Http\Resources\V1;

use App\Domain\Inventory\Models\RoomMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoomMedia
 *
 * Safe representation of a stored Room/Room Type image — id, collection,
 * ordering and the resolved public URL. The disk-relative path, disk name
 * and mediable_type/id are internal and never exposed.
 */
class RoomMediaResource extends JsonResource
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
