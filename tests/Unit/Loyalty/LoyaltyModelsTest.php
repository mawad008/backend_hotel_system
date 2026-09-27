<?php

namespace Tests\Unit\Loyalty;

use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Reservation\Models\Guest;
use Tests\TestCase;

class LoyaltyModelsTest extends TestCase
{
    public function test_guest_has_a_loyalty_account_relation(): void
    {
        $account = LoyaltyAccount::factory()->create();

        $this->assertSame($account->id, Guest::find($account->guest_id)->loyaltyAccount->id);
    }

    public function test_rule_can_earn_only_when_active_with_a_rate(): void
    {
        $this->assertFalse(LoyaltyRule::factory()->make(['is_active' => false, 'earn_points_per_currency' => '1.0'])->canEarn());
        $this->assertFalse(LoyaltyRule::factory()->make(['is_active' => true, 'earn_points_per_currency' => null])->canEarn());
        $this->assertTrue(LoyaltyRule::factory()->make(['is_active' => true, 'earn_points_per_currency' => '1.0'])->canEarn());
    }

    public function test_rule_can_redeem_only_when_active_with_a_point_value(): void
    {
        $this->assertFalse(LoyaltyRule::factory()->make(['is_active' => true, 'redeem_currency_per_point' => null])->canRedeem());
        $this->assertTrue(LoyaltyRule::factory()->make(['is_active' => true, 'redeem_currency_per_point' => '0.01'])->canRedeem());
    }

    public function test_rule_source_types_default_to_reservation(): void
    {
        $this->assertSame(['reservation'], LoyaltyRule::factory()->make(['eligible_source_types' => null])->sourceTypes());
        $this->assertSame(['reservation'], LoyaltyRule::factory()->make(['eligible_source_types' => []])->sourceTypes());
    }

    public function test_transaction_types_and_expire_is_reserved_only(): void
    {
        $this->assertContains('expire', LoyaltyTransaction::TYPES);
        // no code path writes 'expire' — asserted structurally in the architecture test
        $this->assertSame('earn', LoyaltyTransaction::TYPE_EARN);
        $this->assertSame('redeem', LoyaltyTransaction::TYPE_REDEEM);
    }
}
