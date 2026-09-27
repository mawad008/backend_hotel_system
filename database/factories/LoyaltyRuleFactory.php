<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\Loyalty\Models\LoyaltyRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyRule>
 *
 * Default = the real production shape: inactive, no rates (nothing is
 * seeded/defaulted). Use `->active()` in tests that exercise accrual.
 */
class LoyaltyRuleFactory extends Factory
{
    protected $model = LoyaltyRule::class;

    public function definition(): array
    {
        return [
            'hotel_group_id' => HotelGroup::factory(),
            'is_active' => false,
            'earn_points_per_currency' => null,
            'redeem_currency_per_point' => null,
            'eligible_source_types' => [LoyaltyRule::SOURCE_RESERVATION],
        ];
    }

    public function active(string $earnRate = '1.0000', string $redeemValue = '0.0100', int $maxRedeem = 1000000): static
    {
        return $this->state(fn () => [
            'is_active' => true,
            'earn_points_per_currency' => $earnRate,
            'redeem_currency_per_point' => $redeemValue,
            'max_redeem_points' => $maxRedeem,
        ]);
    }
}
