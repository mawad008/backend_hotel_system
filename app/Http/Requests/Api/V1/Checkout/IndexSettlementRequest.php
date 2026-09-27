<?php

namespace App\Http\Requests\Api\V1\Checkout;

use App\Domain\Checkout\Models\Checkout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Explicit allow-list of query parameters for the staff settlements ledger
 * GET /hotels/{hotel}/settlements. Mirrors IndexHotelRequest's
 * filters()/perPage() shape. A "settlement" here is a Checkout record — the
 * domain has no separate Settlement entity (Checkout carries the totals and
 * settlement status).
 */
class IndexSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(Checkout::STATUSES)],
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
