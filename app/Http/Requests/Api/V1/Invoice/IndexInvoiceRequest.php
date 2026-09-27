<?php

namespace App\Http\Requests\Api\V1\Invoice;

use App\Domain\Checkout\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Explicit allow-list of query parameters for the staff invoices ledger
 * GET /hotels/{hotel}/invoices. Mirrors IndexHotelRequest's
 * filters()/perPage() shape.
 */
class IndexInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(Invoice::STATUSES)],
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
