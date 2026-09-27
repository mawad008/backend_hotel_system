<?php

namespace App\Http\Requests\Api\V1\Room;

use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomMedia;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PATCH /hotels/{hotel}/rooms/{room}/media/reorder. `ids`
 * is the full, desired order of the room's gallery media; RoomMediaService
 * rejects a list that is not exactly the current gallery.
 */
class ReorderRoomMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room
            && ($this->user()?->can('manageMedia', $room) ?? false);
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $room = $this->route('room');
            $ids = $this->input('ids');

            if (! $room instanceof Room || ! is_array($ids)) {
                return;
            }

            $gallery = $room->media()
                ->where('collection', RoomMedia::COLLECTION_GALLERY)
                ->pluck('id')
                ->map('intval')
                ->sort()
                ->values()
                ->all();

            $incoming = collect($ids)->map('intval')->sort()->values()->all();

            if ($gallery !== $incoming) {
                $validator->errors()->add('ids', __('api.room_media.reorder_mismatch'));
            }
        });
    }

    /**
     * @return list<int>
     */
    public function orderedIds(): array
    {
        return array_map('intval', $this->validated('ids'));
    }
}
