<?php

namespace App\Http\Requests\Api\V1\Reservation;

use App\Domain\Reservation\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Explicit allow-list of query parameters for GET /reservations. The hotel
 * scope itself is always resolved server-side from the caller's access —
 * `hotel_id` only narrows within it, so a hotel the caller cannot see just
 * yields an empty page, never another hotel's data.
 */
class IndexReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::in([...Reservation::BLOCKING_STATUSES, Reservation::STATUS_CANCELLED])],
            'hotel_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'check_in_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'check_in_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:check_in_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{search: string|null, status: string|null, hotel_id: int|null, check_in_from: string|null, check_in_to: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->query('search', '')) ?: null,
            'status' => $this->query('status') ?: null,
            'hotel_id' => $this->filled('hotel_id') ? (int) $this->query('hotel_id') : null,
            'check_in_from' => $this->query('check_in_from') ?: null,
            'check_in_to' => $this->query('check_in_to') ?: null,
        ];
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }
}
