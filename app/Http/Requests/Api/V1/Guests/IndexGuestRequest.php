<?php

namespace App\Http\Requests\Api\V1\Guests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit allow-list of query parameters for the staff-facing GET /guests
 * directory. Mirrors IndexHotelRequest's filters()/perPage() shape. Not to
 * be confused with app/Http/Requests/Api/V1/Guest/ — that namespace holds
 * guest-APP (auth:guest) requests; this one is staff-facing.
 */
class IndexGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{search: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->query('search') ?: null,
        ];
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }
}
