<?php

namespace App\Http\Resources\V1\Guest;

use App\Domain\Discovery\Models\GuestFavoriteHotel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GuestFavoriteHotel
 */
class GuestFavoriteHotelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'hotel_id' => $this->hotel_id,
            'created_at' => $this->created_at,
        ];
    }
}
