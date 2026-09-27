<?php

namespace Tests\Unit\Loyalty;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Loyalty\Models\LoyaltyRule;

class LoyaltyRuleServiceTest extends LoyaltyTestCase
{
    public function test_rule_is_created_inactive_and_unconfigured_on_first_access(): void
    {
        $group = HotelGroup::factory()->create();

        $rule = $this->ruleService()->ruleFor($group);

        $this->assertFalse($rule->is_active);
        $this->assertNull($rule->earn_points_per_currency);
        $this->assertNull($rule->redeem_currency_per_point);
        $this->assertSame(['reservation'], $rule->sourceTypes());
        $this->assertSame(1, LoyaltyRule::count());

        // second access returns the same row
        $this->assertSame($rule->id, $this->ruleService()->ruleFor($group->fresh())->id);
        $this->assertSame(1, LoyaltyRule::count());
    }

    public function test_update_applies_the_rates_and_audits(): void
    {
        $group = HotelGroup::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $rule = $this->ruleService()->update($group, [
            'is_active' => true,
            'earn_points_per_currency' => '1.2500',
            'redeem_currency_per_point' => '0.0100',
        ], $owner);

        $this->assertTrue($rule->is_active);
        $this->assertSame('1.2500', $rule->earn_points_per_currency);
        $this->assertSame('0.0100', $rule->redeem_currency_per_point);
        $this->assertDatabaseHas('audit_logs', ['action' => 'loyalty_rule.updated', 'actor_id' => $owner->id]);
    }

    public function test_update_ignores_client_supplied_group_id_and_source_types(): void
    {
        $group = HotelGroup::factory()->create();
        $otherGroup = HotelGroup::factory()->create();

        $rule = $this->ruleService()->update($group, [
            'is_active' => true,
            'earn_points_per_currency' => '1.0000',
            'hotel_group_id' => $otherGroup->id,
            'eligible_source_types' => ['minibar', 'anything'],
        ], null);

        $this->assertSame($group->id, $rule->hotel_group_id);
        $this->assertSame(['reservation'], $rule->sourceTypes());
    }

    public function test_a_partial_update_keeps_untouched_fields(): void
    {
        $group = HotelGroup::factory()->create();
        $this->ruleService()->update($group, ['is_active' => true, 'earn_points_per_currency' => '2.0000'], null);

        $rule = $this->ruleService()->update($group->fresh(), ['redeem_currency_per_point' => '0.0250'], null);

        $this->assertTrue($rule->is_active);
        $this->assertSame('2.0000', $rule->earn_points_per_currency);
        $this->assertSame('0.0250', $rule->redeem_currency_per_point);
    }
}
