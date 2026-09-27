<?php

namespace App\Http\Requests\Api\V1\Hotel;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelMedia;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PATCH /hotels/{hotel}/media/reorder. `ids` is the full,
 * desired order of the hotel's gallery media; HotelMediaService rejects a
 * list that is not exactly the current gallery.
 */
class ReorderHotelMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hotel = $this->route('hotel');

        return $hotel instanceof Hotel
            && ($this->user()?->can('manageMedia', $hotel) ?? false);
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
            $hotel = $this->route('hotel');
            $ids = $this->input('ids');

            if (! $hotel instanceof Hotel || ! is_array($ids)) {
                return;
            }

            $gallery = $hotel->media()
                ->where('collection', HotelMedia::COLLECTION_GALLERY)
                ->pluck('id')
                ->map('intval')
                ->sort()
                ->values()
                ->all();

            $incoming = collect($ids)->map('intval')->sort()->values()->all();

            if ($gallery !== $incoming) {
                $validator->errors()->add('ids', __('api.hotel_media.reorder_mismatch'));
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
