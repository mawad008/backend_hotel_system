<?php

namespace Tests\Feature\Rbac;

use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class RolePermissionEndpointTest extends TestCase
{
    public function test_group_owner_can_list_roles_and_permissions(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'group_owner'])
            ->assertJsonFragment(['slug' => 'hotel_manager'])
            ->assertJsonFragment(['slug' => 'reception'])
            // Internal role with no dashboard use — never listed.
            ->assertJsonMissing(['slug' => 'guest']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'hotels.view']);
    }

    public function test_non_owner_roles_cannot_list_roles_or_permissions(): void
    {
        foreach (['hotelManager', 'reception', 'guest'] as $factoryState) {
            $user = User::factory()->{$factoryState}()->create();

            $this->actingAs($user, 'sanctum')->getJson('/api/v1/roles')->assertStatus(403);
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/permissions')->assertStatus(403);
        }
    }

    public function test_reception_does_not_receive_financial_or_user_management_permissions(): void
    {
        $reception = User::factory()->reception()->create();

        $this->assertFalse($reception->hasPermission('users.manage'));
        $this->assertFalse($reception->hasPermission('hotels.manage'));
        $this->assertFalse($reception->hasPermission('hotel-groups.manage'));
        $this->assertTrue($reception->hasPermission('hotels.view'));
    }

    public function test_the_internal_guest_role_cannot_be_viewed_edited_deleted_or_assigned_from_the_dashboard(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $guestRole = Role::query()->where('slug', Role::GUEST)->firstOrFail();
        $managerRole = Role::query()->where('slug', Role::HOTEL_MANAGER)->firstOrFail();

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/roles/{$guestRole->id}")->assertForbidden();
        $this->actingAs($owner, 'sanctum')->putJson("/api/v1/roles/{$guestRole->id}", ['name_en' => 'X'])->assertForbidden();
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/roles/{$guestRole->id}")->assertForbidden();
        $this->assertDatabaseHas('roles', ['slug' => Role::GUEST]);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Someone',
            'email' => 'someone@hotel.test',
            'password' => 'password123',
            'role_id' => $guestRole->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['role_id']);

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/roles/{$managerRole->id}")->assertOk();
    }

    public function test_guest_role_carries_no_staff_permissions(): void
    {
        $guest = User::factory()->guest()->create();

        $this->assertFalse($guest->hasPermission('hotels.view'));
        $this->assertFalse($guest->hasPermission('hotels.manage'));
        $this->assertFalse($guest->hasPermission('users.manage'));
        $this->assertFalse($guest->hasPermission('hotel-groups.manage'));
    }

    public function test_group_owner_has_every_seeded_permission(): void
    {
        $owner = User::factory()->groupOwner()->create();

        foreach ([
            'hotel-groups.manage', 'hotels.view', 'hotels.manage',
            'users.view', 'users.manage', 'roles.view', 'permissions.view',
        ] as $permission) {
            $this->assertTrue($owner->hasPermission($permission), "Expected group owner to have {$permission}");
        }
    }
}
