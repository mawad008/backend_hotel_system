<?php

namespace App\Http\Resources\V1;

use App\Domain\StayServices\Models\FolioCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FolioCharge
 *
 * Safe fields only. `metadata` and internal database detail are absent.
 * No payment/card data is ever present on a folio charge in the first place.
 */
class FolioChargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'hotel_id' => $this->hotel_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unit_amount,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'charged_at' => $this->charged_at,
            'cancelled_at' => $this->cancelled_at,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
