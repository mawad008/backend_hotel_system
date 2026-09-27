<?php

namespace App\Http\Requests\Api\V1\HotelGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHotelGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $group = $this->route('hotel_group');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('hotel_groups', 'slug')->ignore($group)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
