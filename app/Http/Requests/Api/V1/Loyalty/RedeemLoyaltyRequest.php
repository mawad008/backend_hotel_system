<?php

namespace App\Http\Requests\Api\V1\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 10 — validation for
 * POST /api/v1/reservations/{reservation}/loyalty/redeem.
 *
 * The client supplies ONLY how many points to redeem. The account, the
 * guest, the balance, the point value and the resulting ledger delta are
 * all derived server-side. `points` is capped at 10,000,000 (a technical
 * bound so `points × rate` cannot overflow DECIMAL — not a business rule).
 */
class RedeemLoyaltyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'points' => ['required', 'integer', 'min:1', 'max:10000000'],
        ];
    }

    public function points(): int
    {
        return (int) $this->validated('points');
    }
}
