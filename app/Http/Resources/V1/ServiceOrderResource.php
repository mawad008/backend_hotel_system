<?php

namespace App\Http\Resources\V1;

use App\Domain\StayServices\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceOrder
 */
class ServiceOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'hotel_id' => $this->hotel_id,
            'service_id' => $this->service_id,
            'quantity' => $this->quantity,
            'unit_price_snapshot' => $this->unit_price_snapshot,
            'currency_snapshot' => $this->currency_snapshot,
            'total_amount' => $this->total_amount,
            'status' => $this->status,
            'notes' => $this->notes,
            'requested_by_user_id' => $this->requested_by_user_id,
            'requested_at' => $this->requested_at,
            'confirmed_at' => $this->confirmed_at,
            'fulfilled_at' => $this->fulfilled_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
