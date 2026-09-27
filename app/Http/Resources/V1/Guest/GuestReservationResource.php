<?php

namespace App\Http\Resources\V1\Guest;

use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationCancellationService;
use App\Domain\StayServices\Models\FolioCharge;
use App\Support\LocalizedContent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reservation
 *
 * The guest's own view of a reservation. Narrower than the staff
 * ReservationResource: no `created_by_staff_id`, no `room_id` (internal
 * allocation), no `guest_id` (it is always the caller). `status` is the raw
 * state-machine value — the client mirrors the enum and must render every
 * state.
 *
 * `hotel` / `room_type` / `room` light summaries appear only when
 * eager-loaded. `room_type.base_price` is the authoritative nightly rate
 * (Extend Stay prices from it; the Account screen's loyalty-card "per night"
 * figure reads it too — see docs/mobile-phase-11-bookings-account.md). `room`
 * is the physically allocated room (set once a room is assigned / at
 * check-in) — absent for a reservation with no room assigned yet.
 * `currency` is the reservation's own snapshot (stored at creation).
 * `loyalty_discount` is the posted loyalty-redemption credit (positive
 * amount) or null when no points were redeemed on this booking.
 */
class GuestReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $nights = CarbonImmutable::parse($this->check_in)
            ->diffInDays(CarbonImmutable::parse($this->check_out));

        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'room_type_id' => $this->room_type_id,
            'status' => $this->status,
            'check_in' => optional($this->check_in)->toDateString(),
            'check_out' => optional($this->check_out)->toDateString(),
            'nights' => $nights,
            'adults' => $this->adults,
            'children' => $this->children,
            'price_snapshot' => $this->price_snapshot,
            // The booking service fee (snapshotted) and what the guest pays in all.
            'service_fee_amount' => $this->service_fee_amount ?? '0.00',
            'total_amount' => bcadd((string) ($this->price_snapshot ?? '0'), (string) ($this->service_fee_amount ?? '0'), 2),
            // The reservation's own snapshot — never the current platform value.
            'currency' => $this->currency ?: config('payment.currency'),
            'loyalty_discount' => $this->loyaltyDiscount(),
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            // Server-decided policy state (approved 2026-09-26): the app
            // shows the cancel action and its refund text from this alone.
            'cancellation' => app(ReservationCancellationService::class)->evaluate($this->resource)->toArray(),
            // Whether the guest may check themselves in now, and why not.
            'check_in_availability' => app(DigitalAccessService::class)->checkInAvailability($this->resource, DigitalAccessService::CHANNEL_SELF),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'hotel' => $this->whenLoaded('hotel', fn () => [
                'id' => $this->hotel->id,
                'name' => LocalizedContent::resolve($this->hotel->name_i18n, $this->hotel->name),
                'city' => $this->hotel->city,
                // Display name in the request locale (legacy `city` is English).
                'city_name' => $this->hotel->relationLoaded('cityRef') && $this->hotel->cityRef
                    ? LocalizedContent::resolve(
                        ['en' => $this->hotel->cityRef->name_en, 'ar' => $this->hotel->cityRef->name_ar],
                        $this->hotel->city,
                    )
                    : $this->hotel->city,
                'cover_url' => $this->hotel->relationLoaded('cover') ? $this->hotel->cover?->url() : null,
                'reception_phone' => $this->hotel->reception_phone,
                'check_in_mode' => $this->hotel->check_in_mode,
                'check_in_time' => \App\Http\Resources\V1\HotelResource::hhmm($this->hotel->check_in_time),
                // What the deposit hold will be for this booking (shown on
                // the payment screens before the hold is placed).
                'deposit_percentage' => $this->hotel->deposit_percentage,
                'deposit_amount' => $this->hotel->depositFor((string) $this->price_snapshot),
            ]),
            'room_type' => $this->whenLoaded('roomType', fn () => [
                'id' => $this->roomType->id,
                'name' => $this->roomType->name,
                'capacity' => $this->roomType->capacity,
                'base_price' => $this->roomType->base_price,
            ]),
            'room' => $this->whenLoaded('room', fn () => $this->room ? [
                'id' => $this->room->id,
                'room_number' => $this->room->room_number,
            ] : null),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment
                ? new GuestPaymentResource($this->payment)
                : null),
        ];
    }

    private function loyaltyDiscount(): ?string
    {
        $credit = FolioCharge::query()
            ->where('source_type', FolioCharge::SOURCE_LOYALTY_REDEMPTION)
            ->where('source_id', $this->id)
            ->where('status', FolioCharge::STATUS_POSTED)
            ->value('total_amount');

        return $credit === null ? null : bcsub('0', (string) $credit, 2);
    }
}
