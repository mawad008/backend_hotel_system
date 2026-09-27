<?php

namespace Tests\Unit\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class HotelScopedTest extends TestCase
{
    public function test_accessible_by_returns_every_row_for_a_group_owner(): void
    {
        $group = HotelGroup::factory()->create();
        Hotel::factory()->count(3)->create(['hotel_group_id' => $group->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->assertCount(3, Hotel::query()->accessibleBy($owner)->get());
    }

    public function test_accessible_by_filters_to_assigned_hotels_for_non_owners(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $result = Hotel::query()->accessibleBy($manager)->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($hotelA));
    }

    public function test_accessible_by_returns_nothing_for_a_user_with_no_assignments(): void
    {
        $group = HotelGroup::factory()->create();
        Hotel::factory()->count(2)->create(['hotel_group_id' => $group->id]);

        $reception = User::factory()->reception()->create();

        $this->assertCount(0, Hotel::query()->accessibleBy($reception)->get());
    }
}
