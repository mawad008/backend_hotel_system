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
            // Printable-invoice header (staff single-invoice read only): the
            // issuing hotel, the billed guest and the stay. Bilingual names
            // are raw {en, ar} maps — the printout picks its own language.
            'document' => $this->when(
                $this->resource->relationLoaded('reservation') && $this->resource->relationLoaded('hotel'),
                fn () => $this->document(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $hotel = $this->hotel;
        $reservation = $this->reservation;
        $place = fn ($model) => $model === null ? null : ['en' => $model->name_en, 'ar' => $model->name_ar];

        return [
            'hotel' => $hotel === null ? null : [
                'name' => $hotel->name,
                'name_i18n' => $hotel->name_i18n,
                'group_name' => $hotel->hotelGroup?->name,
                'logo_url' => $hotel->logo?->url(),
                'city' => $place($hotel->cityRef) ?? ($hotel->city ? ['en' => $hotel->city, 'ar' => $hotel->city] : null),
                'country' => $place($hotel->countryRef) ?? ($hotel->country ? ['en' => $hotel->country, 'ar' => $hotel->country] : null),
                'phone' => $hotel->reception_phone,
            ],
            'guest' => $reservation?->guest === null ? null : [
                'name' => $reservation->guest->name,
                'phone' => $reservation->guest->phone,
                'email' => $reservation->guest->email,
            ],
            'stay' => $reservation === null ? null : [
                'check_in' => $reservation->check_in?->toDateString(),
                'check_out' => $reservation->check_out?->toDateString(),
                'nights' => $reservation->check_in && $reservation->check_out
                    ? max((int) $reservation->check_in->diffInDays($reservation->check_out), 1)
                    : null,
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'room_type' => $reservation->roomType?->name,
                'room_number' => $reservation->room?->room_number,
                'tax_rate' => $reservation->tax_rate,
            ],
        ];
    }
}
