<?php

namespace Tests\Feature\Rbac;

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
            ->assertJsonFragment(['slug' => 'guest']);

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
