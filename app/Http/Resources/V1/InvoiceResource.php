<?php

namespace App\Http\Resources\V1;

use App\Domain\Checkout\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 9 — the safe HTTP representation of a final invoice.
 *
 * Totals only; no payment secret, provider reference, or card data (an
 * invoice never carries any). `created_by_user_id` is internal staff
 * attribution and is intentionally omitted.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'hotel_id' => $this->hotel_id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'payments_total' => $this->payments_total,
            'outstanding_total' => $this->outstanding_total,
            'issued_at' => $this->issued_at,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
