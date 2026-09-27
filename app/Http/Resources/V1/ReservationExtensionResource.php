<?php

namespace App\Http\Resources\V1;

use App\Domain\Reservation\Models\ReservationExtension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Extend Stay — the safe HTTP representation of one extension record. No
 * internal ids beyond the ones the guest already knows (`reservation_id`) —
 * `folio_charge_id` is surfaced only as a presence flag (`folio_posted`) so
 * the client knows to re-read the folio, not as a raw id to fetch by.
 *
 * @mixin ReservationExtension
 */
class ReservationExtensionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'previous_check_out' => optional($this->previous_check_out)->toDateString(),
            'new_check_out' => optional($this->new_check_out)->toDateString(),
            'nights_added' => $this->nights_added,
            'unit_price' => $this->unit_price,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'folio_posted' => $this->folio_charge_id !== null,
            'created_at' => $this->created_at,
        ];
    }
}
