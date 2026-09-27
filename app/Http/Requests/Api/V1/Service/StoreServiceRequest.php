<?php

namespace App\Http\Requests\Api\V1\Service;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 8 — validation for POST /api/v1/hotels/{hotel}/services.
 *
 * `price` is the one staff-settable money field. It is capped
 * (max 1,000,000.00) and constrained to two decimal places so a computed
 * line total (price x quantity) always fits DECIMAL(12,2) — a technical
 * boundary, not an invented business rule. `is_active` and `hotel_id` are
 * never accepted from the body.
 */
class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hotel = $this->route('hotel');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('hotel_services', 'name')->where('hotel_id', $hotel->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha', 'uppercase'],
            'service_category_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('service_categories', 'id')->where('hotel_id', $hotel->id),
            ],
        ];
    }
}
