<?php

namespace App\Http\Requests\Api\V1\ServiceCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 8 — validation for POST /api/v1/hotels/{hotel}/service-categories.
 * hotel_id and is_active are never accepted from the body — the route hotel
 * always wins and activation has its own endpoints.
 */
class StoreServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hotel = $this->route('hotel');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('service_categories', 'name')->where('hotel_id', $hotel->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
