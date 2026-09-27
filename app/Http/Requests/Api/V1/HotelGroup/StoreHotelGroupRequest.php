<?php

namespace App\Http\Requests\Api\V1\HotelGroup;

use Illuminate\Foundation\Http\FormRequest;

class StoreHotelGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:hotel_groups,slug'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
