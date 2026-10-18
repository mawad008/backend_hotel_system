<?php

namespace App\Http\Requests\Api\V1\Folio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Explicit allow-list of query parameters for the standalone folio ledger
 * GET /hotels/{hotel}/folios. Mirrors IndexHotelRequest's filters()/
 * perPage() shape.
 */
class IndexFolioRequest extends FormRequest
{
    /** `-` prefix = descending. Default `-id`: newest reservation number first. */
    public const SORTS = ['id', '-id', 'check_in', '-check_in', 'check_out', '-check_out'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'outstanding' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'nullable', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{search: string|null, outstanding_only: bool, sort: string}
     */
    public function filters(): array
    {
        return [
            'search' => $this->query('search') ?: null,
            'outstanding_only' => $this->boolean('outstanding'),
            'sort' => $this->query('sort') ?: '-id',
        ];
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }
}
