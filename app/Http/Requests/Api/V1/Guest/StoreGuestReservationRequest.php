<?php

namespace App\Http\Requests\Api\V1\Guest;

use App\Domain\Inventory\Models\RoomType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/guest/reservations.
 *
 * The guest supplies only what the booking screen collects: a room type and
 * the stay dates + party. `guest_id` is taken from the token by the
 * controller and never accepted here; `room_id` / `hotel_id` are never a
 * guest choice — ReservationService derives the hotel and leaves the room
 * unassigned.
 *
 * Business invariants (real availability, the checkout-exclusive overlap
 * math, the price snapshot) stay in ReservationService and are NOT
 * duplicated here — this only rejects obviously bad input and keeps the
 * guest surface consistent with the availability endpoint it just called
 * (active room type of an active hotel; party fits capacity).
 */
class StoreGuestReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['required', 'integer', 'min:0', 'max:20'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $roomTypeId = $this->input('room_type_id');

            if (! $roomTypeId) {
                return;
            }

            /** @var RoomType|null $roomType */
            $roomType = RoomType::query()->with('hotel')->find($roomTypeId);

            // A room type that is inactive, or belongs to an inactive hotel,
            // is not bookable through the guest surface — mirror the guest
            // Discovery visibility rules (identical 404-shaped "not there").
            if ($roomType === null || ! $roomType->is_active || $roomType->hotel === null || ! $roomType->hotel->is_active) {
                $validator->errors()->add('room_type_id', __('api.guest_booking.room_type_unavailable'));

                return;
            }

            $party = (int) $this->input('adults', 0) + (int) $this->input('children', 0);

            if ($party > $roomType->capacity) {
                $validator->errors()->add('adults', __('api.guest_booking.party_exceeds_capacity', [
                    'capacity' => $roomType->capacity,
                ]));
            }
        });
    }

    /**
     * The payload ReservationService::create() expects, with guest_id
     * injected by the controller from the authenticated token.
     *
     * @return array<string, mixed>
     */
    public function reservationData(int $guestId): array
    {
        return [
            'room_type_id' => (int) $this->validated('room_type_id'),
            'guest_id' => $guestId,
            'check_in' => $this->validated('check_in'),
            'check_out' => $this->validated('check_out'),
            'adults' => (int) $this->validated('adults'),
            'children' => (int) $this->validated('children'),
        ];
    }
}
