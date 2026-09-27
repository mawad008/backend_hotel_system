<?php

namespace Tests\Feature\Loyalty;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Loyalty\Models\LoyaltyRule;
use Tests\TestCase;

class LoyaltyRuleApiTest extends TestCase
{
    private function path(HotelGroup $group): string
    {
        return "/api/v1/hotel-groups/{$group->id}/loyalty-rule";
    }

    public function test_group_owner_can_read_the_rule_created_inactive_on_first_read(): void
    {
        $group = HotelGroup::factory()->create();

        $this->actingAs(User::factory()->groupOwner()->create(), 'sanctum')
            ->getJson($this->path($group))
            ->assertOk()
            ->assertJsonPath('data.hotel_group_id', $group->id)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.earn_points_per_currency', null)
            ->assertJsonPath('data.eligible_source_types', ['reservation']);

        $this->assertSame(1, LoyaltyRule::count());
    }

    public function test_group_owner_can_configure_the_rule(): void
    {
        $group = HotelGroup::factory()->create();

        $this->actingAs(User::factory()->groupOwner()->create(), 'sanctum')
            ->putJson($this->path($group), [
                'is_active' => true,
                'earn_points_per_currency' => '1.5000',
                'redeem_currency_per_point' => '0.0100',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.earn_points_per_currency', '1.5000');
    }

    public function test_rate_validation(): void
    {
        $group = HotelGroup::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->putJson($this->path($group), ['earn_points_per_currency' => '-1'])
            ->assertStatus(422)->assertJsonValidationErrors(['earn_points_per_currency']);

        $this->actingAs($owner, 'sanctum')
            ->putJson($this->path($group), ['earn_points_per_currency' => '1.234567'])
            ->assertStatus(422)->assertJsonValidationErrors(['earn_points_per_currency']);

        $this->actingAs($owner, 'sanctum')
            ->putJson($this->path($group), ['is_active' => 'maybe'])
            ->assertStatus(422)->assertJsonValidationErrors(['is_active']);
    }

    public function test_the_earn_rate_may_be_cleared_back_to_null(): void
    {
        $group = HotelGroup::factory()->create();
        LoyaltyRule::factory()->active()->create(['hotel_group_id' => $group->id]);

        $this->actingAs(User::factory()->groupOwner()->create(), 'sanctum')
            ->putJson($this->path($group), ['earn_points_per_currency' => null])
            ->assertOk()
            ->assertJsonPath('data.earn_points_per_currency', null);
    }

    public function test_non_group_owner_roles_are_forbidden(): void
    {
        $group = HotelGroup::factory()->create();

        foreach (['hotelManager', 'reception', 'guest'] as $factory) {
            $this->actingAs(User::factory()->{$factory}()->create(), 'sanctum')
                ->getJson($this->path($group))->assertStatus(403);
            $this->actingAs(User::factory()->{$factory}()->create(), 'sanctum')
                ->putJson($this->path($group), ['is_active' => true])->assertStatus(403);
        }
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson($this->path(HotelGroup::factory()->create()))->assertStatus(401);
    }
}
