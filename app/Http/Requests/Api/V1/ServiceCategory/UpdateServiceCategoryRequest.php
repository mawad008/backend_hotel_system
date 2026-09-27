<?php

namespace App\Http\Requests\Api\V1\ServiceCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * hotel_id and is_active are intentionally absent — the route hotel
     * always wins and is_active only changes via activate/deactivate.
     */
    public function rules(): array
    {
        $hotel = $this->route('hotel');
        $category = $this->route('serviceCategory');

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('service_categories', 'name')->where('hotel_id', $hotel->id)->ignore($category)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
