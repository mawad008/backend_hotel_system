<?php

namespace App\Http\Resources\V1;

use App\Domain\Checkout\Models\Checkout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The safe HTTP representation of one row in the staff settlements ledger.
 * A "settlement" is a Checkout record — the domain has no separate
 * Settlement entity; this differs from CheckoutResource (which wraps a full
 * CheckoutResult — checkout + payment + invoice — returned by the perform/
 * read-one action) by operating directly on the Checkout model for a plain
 * ledger list. No provider reference or gateway detail exposed.
 *
 * @mixin Checkout
 */
class SettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'hotel_id' => $this->hotel_id,
            'status' => $this->status,
            'charges_total' => $this->charges_total,
            'payments_total' => $this->payments_total,
            'outstanding_total' => $this->outstanding_total,
            'currency' => $this->currency,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
