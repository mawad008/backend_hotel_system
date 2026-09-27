<?php

namespace App\Http\Requests\Api\V1\Service;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * hotel_id and is_active are intentionally absent (route hotel wins;
     * activation has its own endpoints). Historical service orders keep
     * their own price snapshot, so changing `price` here never rewrites a
     * past charge.
     */
    public function rules(): array
    {
        $hotel = $this->route('hotel');
        $service = $this->route('service');

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('hotel_services', 'name')->where('hotel_id', $hotel->id)->ignore($service)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha', 'uppercase'],
            'service_category_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('service_categories', 'id')->where('hotel_id', $hotel->id),
            ],
        ];
    }
}
