<?php

namespace App\Http\Resources\V1;

use App\Domain\Loyalty\Models\LoyaltyRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoyaltyRule
 */
class LoyaltyRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_group_id' => $this->hotel_group_id,
            'is_active' => $this->is_active,
            'earn_points_per_currency' => $this->earn_points_per_currency,
            'redeem_currency_per_point' => $this->redeem_currency_per_point,
            'max_redeem_points' => (int) ($this->max_redeem_points ?? 0),
            'eligible_source_types' => $this->sourceTypes(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
