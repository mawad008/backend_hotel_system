<?php

namespace App\Domain\Loyalty\Repositories;

use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyRuleRepositoryInterface;

class EloquentLoyaltyRuleRepository implements LoyaltyRuleRepositoryInterface
{
    public function findByHotelGroup(int $hotelGroupId): ?LoyaltyRule
    {
        return LoyaltyRule::query()->where('hotel_group_id', $hotelGroupId)->first();
    }

    public function create(array $data): LoyaltyRule
    {
        return LoyaltyRule::create($data)->refresh();
    }

    public function update(LoyaltyRule $rule, array $data): LoyaltyRule
    {
        $rule->update($data);

        return $rule->refresh();
    }
}
