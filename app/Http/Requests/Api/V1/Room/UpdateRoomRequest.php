<?php

namespace App\Http\Requests\Api\V1\Room;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * hotel_id and status are intentionally absent — status changes go
     * exclusively through the dedicated status endpoint
     * (UpdateRoomStatusRequest / RoomService::transitionStatus()), and
     * hotel_id is never an authority-changing field. A new room_type_id
     * must belong to this Room's own hotel — that invariant is enforced
     * by RoomService, not duplicated here.
     */
    public function rules(): array
    {
        $hotel = $this->route('hotel');
        $room = $this->route('room');

        return [
            'room_type_id' => ['sometimes', 'integer', 'exists:room_types,id'],
            'room_number' => ['sometimes', 'string', 'max:50', Rule::unique('rooms', 'room_number')->where('hotel_id', $hotel->id)->ignore($room)],
        ];
    }
}
