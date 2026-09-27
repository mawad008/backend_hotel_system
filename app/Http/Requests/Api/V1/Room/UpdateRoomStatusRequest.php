<?php

namespace App\Http\Requests\Api\V1\Room;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `booked` is rejected here at the validation layer for any target
     * value — it is reserved for the future Reservations domain and can
     * never be reached through this endpoint. Whether the *transition*
     * from the room's current status is actually allowed (e.g. a
     * same-state no-op, or from `booked`) is a business rule enforced by
     * RoomService::transitionStatus(), not duplicated here.
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['available', 'under_maintenance'])],
        ];
    }
}
