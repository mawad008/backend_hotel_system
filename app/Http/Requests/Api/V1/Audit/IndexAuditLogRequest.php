<?php

namespace App\Http\Requests\Api\V1\Audit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit allow-list of query parameters for the staff audit trail
 * (GET /hotels/{hotel}/audit-log, GET /audit-log). Mirrors IndexHotelRequest's
 * filters()/perPage() shape.
 */
class IndexAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actor_id' => ['sometimes', 'nullable', 'integer'],
            'action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'auditable_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hotel_id' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{actor_id: int|null, action: string|null, auditable_type: string|null, from: string|null, to: string|null}
     */
    public function filters(): array
    {
        return [
            'actor_id' => $this->filled('actor_id') ? (int) $this->query('actor_id') : null,
            'action' => $this->query('action') ?: null,
            'auditable_type' => $this->query('auditable_type') ?: null,
            'from' => $this->query('from') ?: null,
            'to' => $this->query('to') ?: null,
        ];
    }

    /**
     * Global feed only — the hotel_id filter narrows across every hotel.
     */
    public function hotelId(): ?int
    {
        return $this->filled('hotel_id') ? (int) $this->query('hotel_id') : null;
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 20);
    }
}
