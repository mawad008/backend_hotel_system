<?php

namespace Tests\Unit\Loyalty;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Loyalty\Policies\LoyaltyPolicy;
use App\Domain\Loyalty\Policies\LoyaltyRulePolicy;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoyaltyPolicyTest extends TestCase
{
    /**
     * @return array<string, array{string, bool, bool, bool}>
     *                                                        factory => [loyalty.view, loyalty.manage, loyalty.rules.manage]
     */
    public static function roleMatrix(): array
    {
        return [
            'group owner' => ['groupOwner', true, true, true],
            'hotel manager' => ['hotelManager', true, true, false],
            'reception' => ['reception', true, false, false],
            'guest' => ['guest', false, false, false],
        ];
    }

    #[DataProvider('roleMatrix')]
    public function test_reservation_scoped_loyalty_policy(string $factory, bool $canView, bool $canManage): void
    {
        $hotel = Hotel::factory()->create();
        $user = User::factory()->{$factory}()->create();
        $user->hotels()->attach($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);

        $this->assertSame($canView, app(LoyaltyPolicy::class)->view($user, $reservation));
        $this->assertSame($canManage, app(LoyaltyPolicy::class)->manage($user, $reservation));
    }

    #[DataProvider('roleMatrix')]
    public function test_rule_policy_is_group_owner_only(string $factory, bool $canView, bool $canManage, bool $canRules): void
    {
        $group = HotelGroup::factory()->create();
        $user = User::factory()->{$factory}()->create();

        $this->assertSame($canRules, app(LoyaltyRulePolicy::class)->view($user, $group));
        $this->assertSame($canRules, app(LoyaltyRulePolicy::class)->manage($user, $group));
    }

    public function test_cross_hotel_is_denied_even_with_the_permission(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);
        $reservation = Reservation::factory()->create(['hotel_id' => $other->id]);

        $this->assertFalse(app(LoyaltyPolicy::class)->view($manager, $reservation));
        $this->assertFalse(app(LoyaltyPolicy::class)->manage($manager, $reservation));
    }

    public function test_group_owner_bypasses_hotel_scope(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = Reservation::factory()->create(['hotel_id' => Hotel::factory()->create()->id]);

        $this->assertTrue(app(LoyaltyPolicy::class)->manage($owner, $reservation));
    }
}
