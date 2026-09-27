<?php

namespace App\Http\Resources\V1;

use App\Domain\Payment\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 5D — the safe HTTP representation of a Payment.
 *
 * Only application-level fields are exposed. Provider references,
 * transaction rows, `metadata`, webhook data, idempotency keys and any
 * internal/database detail are deliberately absent (Phase 5D §17).
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'hotel_id' => $this->hotel_id,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'hold_expires_at' => $this->hold_expires_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
