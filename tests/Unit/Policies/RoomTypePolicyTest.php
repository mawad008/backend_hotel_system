<?php

namespace Tests\Unit\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\Permission;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Policies\RoomTypePolicy;
use Tests\TestCase;

/**
 * `inventory.view`/`inventory.manage` are not yet part of
 * RolePermissionSeeder (that update is out of Phase 2B's scope), so each
 * test grants them directly to the role under test rather than touching
 * the shared seeder.
 */
class RoomTypePolicyTest extends TestCase
{
    private RoomTypePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new RoomTypePolicy(app(HotelAccessService::class));
    }

    private function grant(User $user, string $slug): void
    {
        $permission = Permission::firstOrCreate(['slug' => $slug], ['name_en' => $slug, 'description_en' => $slug]);
        $user->role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->unsetRelation('role');
    }

    public function test_group_owner_can_view_and_manage_any_hotels_room_types(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();
        $this->grant($owner, 'inventory.view');
        $this->grant($owner, 'inventory.manage');

        $this->assertTrue($this->policy->viewAny($owner, $hotel));
        $this->assertTrue($this->policy->view($owner, $roomType));
        $this->assertTrue($this->policy->create($owner, $hotel));
        $this->assertTrue($this->policy->update($owner, $roomType));
    }

    public function test_assigned_hotel_manager_can_view_and_manage_their_hotels_room_types(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);
        $this->grant($manager, 'inventory.view');
        $this->grant($manager, 'inventory.manage');

        $this->assertTrue($this->policy->viewAny($manager, $hotel));
        $this->assertTrue($this->policy->view($manager, $roomType));
        $this->assertTrue($this->policy->create($manager, $hotel));
        $this->assertTrue($this->policy->update($manager, $roomType));
    }

    public function test_unassigned_hotel_manager_is_denied_on_another_hotel(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);
        $this->grant($manager, 'inventory.view');
        $this->grant($manager, 'inventory.manage');

        $this->assertFalse($this->policy->viewAny($manager, $hotelB));
        $this->assertFalse($this->policy->view($manager, $roomTypeB));
        $this->assertFalse($this->policy->create($manager, $hotelB));
        $this->assertFalse($this->policy->update($manager, $roomTypeB));
    }

    public function test_reception_can_view_but_not_manage_their_hotels_room_types(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $this->grant($reception, 'inventory.view');
        // deliberately NOT granted inventory.manage

        $this->assertTrue($this->policy->viewAny($reception, $hotel));
        $this->assertTrue($this->policy->view($reception, $roomType));
        $this->assertFalse($this->policy->create($reception, $hotel));
        $this->assertFalse($this->policy->update($reception, $roomType));
    }

    public function test_reception_is_denied_even_if_manage_permission_is_granted_but_hotel_is_unassigned(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotelA);
        $this->grant($reception, 'inventory.view');

        $this->assertFalse($this->policy->view($reception, $roomTypeB));
    }

    public function test_guest_has_no_inventory_access(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $guest = User::factory()->guest()->create();

        $this->assertFalse($this->policy->viewAny($guest, $hotel));
        $this->assertFalse($this->policy->view($guest, $roomType));
        $this->assertFalse($this->policy->create($guest, $hotel));
        $this->assertFalse($this->policy->update($guest, $roomType));
    }
}
