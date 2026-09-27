<?php

namespace App\Http\Requests\Api\V1\RoomType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hotel = $this->route('hotel');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('room_types', 'name')->where('hotel_id', $hotel->id)],
            'base_price' => ['required', 'numeric', 'min:0'],
            'capacity' => ['required', 'integer', 'min:1'],
            'amenities' => ['sometimes', 'nullable', 'array'],
            'amenities.*' => ['string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            // Guest-facing specs (Room Detail / room cards), per-locale where textual.
            'bed_type_i18n' => ['sometimes', 'nullable', 'array'],
            'bed_type_i18n.*' => ['nullable', 'string', 'max:60'],
            'view_i18n' => ['sometimes', 'nullable', 'array'],
            'view_i18n.*' => ['nullable', 'string', 'max:60'],
            'area_sqm' => ['sometimes', 'nullable', 'integer', 'between:1,2000'],
            'breakfast_included' => ['sometimes', 'boolean'],
            'refundable' => ['sometimes', 'boolean'],
            // Room Detail content: optional badge + "the rate includes" list.
            'tag_i18n' => ['sometimes', 'nullable', 'array'],
            'tag_i18n.*' => ['nullable', 'string', 'max:40'],
            'inclusions_i18n' => ['sometimes', 'nullable', 'array', 'max:15'],
            'inclusions_i18n.*' => ['array'],
            'inclusions_i18n.*.en' => ['nullable', 'string', 'max:80'],
            'inclusions_i18n.*.ar' => ['nullable', 'string', 'max:80'],
            'custom_specs' => ['sometimes', 'nullable', 'array', 'max:20'],
            'custom_specs.*' => ['array'],
            'custom_specs.*.label_i18n' => ['required', 'array'],
            'custom_specs.*.label_i18n.en' => ['nullable', 'string', 'max:60'],
            'custom_specs.*.label_i18n.ar' => ['nullable', 'string', 'max:60'],
            'custom_specs.*.value_i18n' => ['required', 'array'],
            'custom_specs.*.value_i18n.en' => ['nullable', 'string', 'max:120'],
            'custom_specs.*.value_i18n.ar' => ['nullable', 'string', 'max:120'],
        ];
    }
}
