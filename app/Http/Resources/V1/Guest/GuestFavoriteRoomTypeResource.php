<?php

namespace App\Http\Resources\V1\Guest;

use App\Domain\Discovery\Models\GuestFavoriteRoomType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GuestFavoriteRoomType
 *
 * Enough to render a saved-room row without an availability search: the
 * room type's name, its hotel and its cover (`gallery[0]`, the same cover
 * rule as `PublicRoomTypeResource`). Price/availability for a stay still
 * come from the availability endpoint.
 */
class GuestFavoriteRoomTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $roomType = $this->roomType;

        return [
            'room_type_id' => $this->room_type_id,
            'hotel_id' => $roomType?->hotel_id,
            'name' => $roomType?->name,
            'cover_url' => $roomType?->galleryMedia->first()?->url(),
            'created_at' => $this->created_at,
        ];
    }
}
