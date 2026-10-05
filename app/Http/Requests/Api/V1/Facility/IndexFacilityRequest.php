<?php

namespace App\Http\Requests\Api\V1\Facility;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Explicit allow-list of query parameters for GET /facilities. Anything not
 * listed here is ignored — no arbitrary column filtering.
 *
 * `all=1` returns every active facility unpaginated (the Hotel create/edit
 * picker); otherwise the endpoint returns a normal paginated catalog list.
 */
class IndexFacilityRequest extends FormRequest
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
            'sort' => ['sometimes', 'nullable', Rule::in(['name', '-name', 'key', '-key', 'sort_order', '-sort_order'])],
            'all' => ['sometimes', 'boolean'],
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
