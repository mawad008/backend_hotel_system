<?php

namespace App\Http\Requests\Api\V1\Reservation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit allow-list of query parameters for the staff front-desk lists
 * GET /hotels/{hotel}/{arrivals|departures|in-house}. `date` applies only
 * to arrivals/departures (defaults to today, resolved by the controller —
 * never a business default invented here); in-house ignores it.
 */
class IndexFrontDeskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function requestedDate(): ?string
    {
        return $this->query('date') ?: null;
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }
}
