<?php

namespace App\Http\Requests\Api\V1\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 10 — validation for
 * PUT /api/v1/hotel-groups/{hotel_group}/loyalty-rule (Group Owner only).
 *
 * All fields are `sometimes` — a partial config update. The rates are
 * non-negative decimals with up to 4 fractional digits; nothing is
 * defaulted (Phase 0 §13). `hotel_group_id` and `eligible_source_types` are
 * never accepted from the body.
 */
class UpdateLoyaltyRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'earn_points_per_currency' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,4'],
            'redeem_currency_per_point' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,4'],
            // Most points one booking may redeem; 0 = redemption off.
            'max_redeem_points' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
