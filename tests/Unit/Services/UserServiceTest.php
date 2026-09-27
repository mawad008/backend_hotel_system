<?php

namespace Tests\Unit\Services;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\UserService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserServiceTest extends TestCase
{
    public function test_create_hashes_the_password_and_syncs_hotel_access(): void
    {
        $group = HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $role = Role::where('slug', Role::HOTEL_MANAGER)->firstOrFail();
        $owner = User::factory()->groupOwner()->create();

        $service = app(UserService::class);

        $user = $service->create([
            'name' => 'New Manager',
            'email' => 'new-manager@example.com',
            'password' => 'plain-password',
            'role_id' => $role->id,
            'hotel_ids' => [$hotel->id],
        ], $owner);

        $this->assertNotSame('plain-password', $user->password);
        $this->assertTrue(Hash::check('plain-password', $user->password));
        $this->assertTrue($user->hotels()->whereKey($hotel->id)->exists());
    }

    public function test_update_without_a_password_keeps_the_existing_hash(): void
    {
        $user = User::factory()->hotelManager()->create(['password' => 'original-password']);
        $originalHash = $user->password;
        $owner = User::factory()->groupOwner()->create();

        $service = app(UserService::class);
        $service->update($user, ['name' => 'Renamed'], $owner);

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertSame('Renamed', $user->fresh()->name);
    }

    public function test_update_with_a_new_password_rehashes_it(): void
    {
        $user = User::factory()->hotelManager()->create(['password' => 'original-password']);
        $owner = User::factory()->groupOwner()->create();

        $service = app(UserService::class);
        $service->update($user, ['password' => 'new-password'], $owner);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_update_leaves_hotel_access_untouched_when_hotel_ids_is_not_supplied(): void
    {
        $group = HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);
        $owner = User::factory()->groupOwner()->create();

        $service = app(UserService::class);
        $service->update($manager, ['name' => 'Renamed'], $owner);

        $this->assertTrue($manager->hotels()->whereKey($hotel->id)->exists());
    }
}
