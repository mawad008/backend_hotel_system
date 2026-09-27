<?php

namespace App\Http\Resources\V1;

use App\Domain\Checkout\Services\CheckoutResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 9 — the safe HTTP representation of a checkout outcome.
 *
 * Exposes only business information: the reservation reference and status,
 * the checkout status, the folio totals (snapshot), the payment settlement
 * status, and the invoice (when issued). It NEVER exposes a provider
 * reference, a raw gateway payload, card data, a credential, or an internal
 * exception detail.
 *
 * @mixin CheckoutResult
 */
class CheckoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CheckoutResult $result */
        $result = $this->resource;
        $checkout = $result->checkout;

        return [
            'reservation' => [
                'id' => $checkout->reservation_id,
                'hotel_id' => $checkout->hotel_id,
                'status' => $checkout->reservation->status,
            ],
            'checkout' => [
                'status' => $checkout->status,
                'started_at' => $checkout->started_at,
                'completed_at' => $checkout->completed_at,
            ],
            'totals' => [
                'charges_total' => $checkout->charges_total,
                'payments_total' => $checkout->payments_total,
                'outstanding_total' => $checkout->outstanding_total,
            ],
            'currency' => $checkout->currency,
            'payment' => $result->payment === null ? null : [
                'status' => $result->payment->status,
                'amount' => $result->payment->amount,
                'currency' => $result->payment->currency,
            ],
            'invoice' => $result->invoice === null ? null : [
                'id' => $result->invoice->id,
                'invoice_number' => $result->invoice->invoice_number,
                'status' => $result->invoice->status,
                'issued_at' => $result->invoice->issued_at,
            ],
        ];
    }
}
