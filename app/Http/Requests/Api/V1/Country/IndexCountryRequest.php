<?php

namespace App\Http\Requests\Api\V1\Country;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit allow-list of query parameters for GET /countries. Anything not
 * listed here is ignored — no arbitrary column filtering.
 */
class IndexCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{search: string|null, is_active: bool|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->query('search') ?: null,
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : null,
        ];
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }
}
