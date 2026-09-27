<?php

namespace App\Domain\Loyalty\Repositories\Contracts;

use App\Domain\Loyalty\Models\LoyaltyRule;

interface LoyaltyRuleRepositoryInterface
{
    public function findByHotelGroup(int $hotelGroupId): ?LoyaltyRule;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LoyaltyRule;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LoyaltyRule $rule, array $data): LoyaltyRule;
}
