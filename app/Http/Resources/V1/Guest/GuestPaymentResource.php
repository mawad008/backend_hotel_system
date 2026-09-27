<?php

namespace App\Http\Resources\V1\Guest;

use App\Domain\Payment\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 *
 * The guest's view of their reservation's deposit payment. Same safe subset
 * as the staff PaymentResource (no provider refs, transactions, metadata,
 * idempotency keys) minus `hotel_id` — a guest has no hotel-scope concept.
 * `status` is the raw Payment state-machine value; the client mirrors the
 * enum.
 */
class GuestPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'hold_expires_at' => $this->hold_expires_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
