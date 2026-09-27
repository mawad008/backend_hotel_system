<?php

namespace App\Http\Resources\V1;

use App\Domain\StayServices\Services\Folio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 8 — the reservation folio read model (Phase 0 §4/§6.4).
 *
 * Exposes: the reservation reference, every folio charge, the deterministic
 * money totals, and a minimal payment summary. It deliberately does NOT
 * expose any card data, provider reference, or internal payment detail —
 * only the payment's own status, amount and currency, plus whether that
 * amount is actually captured money.
 *
 * @mixin Folio
 */
class FolioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Folio $folio */
        $folio = $this->resource;

        return [
            'reservation' => [
                'id' => $folio->reservation->id,
                'hotel_id' => $folio->reservation->hotel_id,
                'guest_id' => $folio->reservation->guest_id,
                'status' => $folio->reservation->status,
            ],
            'currency' => $folio->currency,
            'charges' => FolioChargeResource::collection($folio->charges),
            'totals' => [
                'charges_total' => $folio->chargesTotal,
                'payments_total' => $folio->paymentsTotal,
                'outstanding_total' => $folio->outstandingTotal,
            ],
            'payment_summary' => $folio->payment === null ? null : [
                'status' => $folio->payment->status,
                'amount' => $folio->payment->amount,
                'currency' => $folio->payment->currency,
                'is_captured' => $folio->isPaymentCaptured(),
            ],
        ];
    }
}
