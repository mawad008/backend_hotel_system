<?php

namespace Tests\Unit\Services;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use Tests\TestCase;

class HotelAccessServiceTest extends TestCase
{
    public function test_group_owner_can_access_any_hotel(): void
    {
        $group = HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $owner = User::factory()->groupOwner()->create();

        $service = app(HotelAccessService::class);

        $this->assertTrue($service->canAccessHotel($owner, $hotel->id));
    }

    public function test_hotel_manager_can_only_access_assigned_hotels(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $service = app(HotelAccessService::class);

        $this->assertTrue($service->canAccessHotel($manager, $hotelA->id));
        $this->assertFalse($service->canAccessHotel($manager, $hotelB->id));
    }

    public function test_sync_hotel_access_replaces_the_users_assignments(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $service = app(HotelAccessService::class);
        $service->syncHotelAccess($manager, [$hotelB->id]);

        $manager->unsetRelation('hotels');
        $this->assertFalse($manager->hotels()->whereKey($hotelA->id)->exists());
        $this->assertTrue($manager->hotels()->whereKey($hotelB->id)->exists());
    }
}
