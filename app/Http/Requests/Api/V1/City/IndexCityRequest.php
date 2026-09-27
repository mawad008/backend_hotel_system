<?php

namespace App\Http\Requests\Api\V1\City;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit allow-list of query parameters for GET /cities.
 */
class IndexCityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{search: string|null, is_active: bool|null, country_id: int|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->query('search') ?: null,
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : null,
            'country_id' => $this->query('country_id') ? (int) $this->query('country_id') : null,
        ];
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }
}
