<?php

namespace App\Http\Requests\Api\V1\Payment;

use App\Domain\Payment\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Explicit allow-list of query parameters for the staff payments ledger
 * GET /hotels/{hotel}/payments. Mirrors IndexHotelRequest's
 * filters()/perPage() shape.
 */
class IndexPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(Payment::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{status: string|null}
     */
    public function filters(): array
    {
        return [
            'status' => $this->query('status') ?: null,
        ];
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }
}
