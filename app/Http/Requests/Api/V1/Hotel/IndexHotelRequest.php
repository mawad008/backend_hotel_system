<?php

namespace App\Http\Requests\Api\V1\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Explicit allow-list of query parameters for GET /hotels. Anything not
 * listed here is ignored — no arbitrary column filtering. Real server-side
 * search/status filter/sort over the caller's hotel-scoped results (the
 * scope itself is always resolved server-side, never from a query param).
 */
class IndexHotelRequest extends FormRequest
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
            'sort' => ['sometimes', 'nullable', Rule::in(['name', '-name', 'created_at', '-created_at'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{search: string|null, is_active: bool|null, sort: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->query('search') ?: null,
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : null,
            'sort' => $this->query('sort') ?: null,
        ];
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }
}
