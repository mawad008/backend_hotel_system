<?php

namespace App\Http\Resources\V1;

use App\Domain\Loyalty\Models\LoyaltyTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoyaltyTransaction
 *
 * Safe ledger fields only. `metadata` holds a couple of non-sensitive
 * figures (earn base, notional redeemed value) and is exposed; there is
 * never any card / payment / provider data on a loyalty entry.
 */
class LoyaltyTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'points' => $this->points,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
        ];
    }
}
