<?php

namespace App\Http\Requests\Api\V1\Room;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * hotel_id and status are intentionally absent — hotel_id always
     * comes from the route, and a Room always starts `available`
     * (enforced by RoomService::create()). Whether room_type_id actually
     * belongs to this hotel is a business invariant, not a validation
     * rule, and is checked by RoomService, not here.
     */
    public function rules(): array
    {
        $hotel = $this->route('hotel');

        return [
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'room_number' => ['required', 'string', 'max:50', Rule::unique('rooms', 'room_number')->where('hotel_id', $hotel->id)],
        ];
    }
}
