<?php

namespace App\Http\Requests\Api\V1\RoomType;

use App\Domain\Inventory\Models\RoomMedia;
use App\Domain\Inventory\Models\RoomType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PATCH /hotels/{hotel}/room-types/{roomType}/media/reorder.
 * `ids` is the full, desired order of the room type's gallery media;
 * RoomMediaService rejects a list that is not exactly the current gallery.
 */
class ReorderRoomTypeMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $roomType = $this->route('roomType');

        return $roomType instanceof RoomType
            && ($this->user()?->can('manageMedia', $roomType) ?? false);
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
            $roomType = $this->route('roomType');
            $ids = $this->input('ids');

            if (! $roomType instanceof RoomType || ! is_array($ids)) {
                return;
            }

            $gallery = $roomType->media()
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
