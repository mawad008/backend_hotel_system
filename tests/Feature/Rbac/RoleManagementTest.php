<?php

namespace Tests\Feature\Rbac;

use App\Domain\IdentityAccess\Models\Permission;
use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    public function test_group_owner_can_create_a_custom_role_with_permissions(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $permissionIds = Permission::query()->whereIn('slug', ['hotels.view', 'inventory.view'])->pluck('id');

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/roles', [
            'name_en' => 'Revenue Manager',
            'name_ar' => 'مدير الإيرادات',
            'description_en' => 'Manages pricing and availability.',
            'description_ar' => 'يدير التسعير والتوافر.',
            'permission_ids' => $permissionIds->all(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name_en', 'Revenue Manager')
            ->assertJsonPath('data.name_ar', 'مدير الإيرادات')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permissions_count', 2);

        $this->assertDatabaseHas('roles', ['name_en' => 'Revenue Manager', 'is_system' => false]);
    }

    public function test_creating_a_role_validates_required_fields(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/roles', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_en', 'name_ar']);
    }

    public function test_group_owner_can_update_a_roles_translations_and_permissions_and_it_persists_on_reload(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $role = Role::query()->create([
            'name_en' => 'Draft Role',
            'name_ar' => 'دور مسودة',
            'slug' => 'draft-role',
            'description_en' => null,
            'description_ar' => null,
            'is_system' => false,
        ]);
        $permissionId = Permission::query()->where('slug', 'hotels.view')->value('id');

        $this->actingAs($owner, 'sanctum')->putJson("/api/v1/roles/{$role->id}", [
            'name_en' => 'Front Desk Supervisor',
            'name_ar' => 'مشرف الاستقبال',
            'permission_ids' => [$permissionId],
        ])->assertOk()
            ->assertJsonPath('data.name_en', 'Front Desk Supervisor');

        $reloaded = $this->actingAs($owner, 'sanctum')->getJson("/api/v1/roles/{$role->id}")->assertOk();
        $reloaded->assertJsonPath('data.name_en', 'Front Desk Supervisor')
            ->assertJsonPath('data.name_ar', 'مشرف الاستقبال')
            ->assertJsonPath('data.permissions_count', 1);
    }

    public function test_non_owner_cannot_create_update_or_delete_roles(): void
    {
        $manager = User::factory()->hotelManager()->create();
        $role = Role::query()->create([
            'name_en' => 'Draft Role',
            'name_ar' => 'دور مسودة',
            'slug' => 'draft-role-2',
            'is_system' => false,
        ]);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/roles', [
            'name_en' => 'X', 'name_ar' => 'س',
        ])->assertStatus(403);

        $this->actingAs($manager, 'sanctum')->putJson("/api/v1/roles/{$role->id}", [
            'name_en' => 'X', 'name_ar' => 'س',
        ])->assertStatus(403);

        $this->actingAs($manager, 'sanctum')->deleteJson("/api/v1/roles/{$role->id}")->assertStatus(403);
    }

    public function test_a_system_role_cannot_be_deleted(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $guestRole = Role::where('slug', Role::GUEST)->firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/roles/{$guestRole->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['id' => $guestRole->id]);
    }

    public function test_a_role_assigned_to_users_cannot_be_deleted(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $role = Role::query()->create([
            'name_en' => 'Occupied Role',
            'name_ar' => 'دور مشغول',
            'slug' => 'occupied-role',
            'is_system' => false,
        ]);
        User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_an_unassigned_custom_role_can_be_deleted(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $role = Role::query()->create([
            'name_en' => 'Unused Role',
            'name_ar' => 'دور غير مستخدم',
            'slug' => 'unused-role',
            'is_system' => false,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_validation_errors_are_localized_in_arabic(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->withHeaders(['X-Locale' => 'ar'])
            ->postJson('/api/v1/roles', [])
            ->assertStatus(422)
            ->assertJsonFragment(['name_en' => ['هذا الحقل مطلوب.']]);
    }

    public function test_permissions_endpoint_returns_bilingual_group_labels(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/permissions')
            ->assertOk();

        $response->assertJsonFragment([
            'slug' => 'hotels.view',
            'group' => 'hotels',
            'group_label_en' => 'Hotels',
            'group_label_ar' => 'الفنادق',
        ]);
    }
}
