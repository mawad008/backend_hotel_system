<?php

namespace Tests\Unit\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class UserRbacTest extends TestCase
{
    public function test_is_group_owner_reflects_the_users_role(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $manager = User::factory()->hotelManager()->create();

        $this->assertTrue($owner->isGroupOwner());
        $this->assertFalse($manager->isGroupOwner());
    }

    public function test_has_permission_checks_the_users_role_permissions(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reception = User::factory()->reception()->create();

        $this->assertTrue($owner->hasPermission('users.manage'));
        $this->assertFalse($reception->hasPermission('users.manage'));
        $this->assertTrue($reception->hasPermission('hotels.view'));
    }

    public function test_authorized_hotel_ids_reflects_the_pivot_table(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();

        $this->assertSame([], $manager->authorizedHotelIds());

        $manager->hotels()->attach([$hotelA->id, $hotelB->id]);
        $manager->unsetRelation('hotels');

        $ids = $manager->authorizedHotelIds();
        sort($ids);
        $this->assertSame([$hotelA->id, $hotelB->id], $ids);
    }

    public function test_a_user_without_a_role_has_no_permissions(): void
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->setRelation('role', null);

        $this->assertFalse($manager->hasPermission('hotels.view'));
    }
}
