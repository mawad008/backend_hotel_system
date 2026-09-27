<?php

namespace App\Http\Requests\Api\V1\Reservation;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * hotel_id, status, price_snapshot, created_by_staff_id,
     * cancelled_at, and cancellation_reason are intentionally absent —
     * ReservationService forces/derives every one of them (Phase 3B).
     * Whether room_type_id/room_id/guest_id are mutually consistent
     * (hotel match, room-type match) is a business invariant enforced by
     * ReservationService, not duplicated here.
     */
    public function rules(): array
    {
        return [
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'guest_id' => ['required', 'integer', 'exists:guests,id'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
        ];
    }
}
