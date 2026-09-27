<?php

namespace Tests\Feature\User;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    public function test_group_owner_can_create_a_hotel_manager_with_hotel_access(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $group = HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $role = Role::where('slug', Role::HOTEL_MANAGER)->firstOrFail();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Manager A',
            'email' => 'manager-a@example.com',
            'password' => 'password123',
            'role_id' => $role->id,
            'hotel_ids' => [$hotel->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'manager-a@example.com')
            ->assertJsonPath('data.hotels.0.id', $hotel->id);

        $created = User::where('email', 'manager-a@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('password123', $created->password));
        $this->assertTrue($created->hotels()->whereKey($hotel->id)->exists());
    }

    public function test_group_owner_can_update_a_users_role_and_hotel_access(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $response = $this->actingAs($owner, 'sanctum')->putJson("/api/v1/users/{$manager->id}", [
            'hotel_ids' => [$hotelB->id],
        ]);

        $response->assertOk();
        $manager->refresh();
        $this->assertFalse($manager->hotels()->whereKey($hotelA->id)->exists());
        $this->assertTrue($manager->hotels()->whereKey($hotelB->id)->exists());
    }

    public function test_group_owner_can_delete_a_user(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $manager = User::factory()->hotelManager()->create();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/users/{$manager->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $manager->id]);
    }

    public function test_group_owner_cannot_delete_themselves(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/users/{$owner->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_hotel_manager_cannot_list_or_manage_users(): void
    {
        $manager = User::factory()->hotelManager()->create();
        $other = User::factory()->reception()->create();

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/users')->assertStatus(403);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'X',
            'email' => 'x@example.com',
            'password' => 'password123',
            'role_id' => $other->role_id,
        ])->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/users/{$other->id}")
            ->assertStatus(403);
    }

    public function test_reception_cannot_manage_users(): void
    {
        $reception = User::factory()->reception()->create();
        $other = User::factory()->reception()->create();

        $this->actingAs($reception, 'sanctum')->getJson('/api/v1/users')->assertStatus(403);
        $this->actingAs($reception, 'sanctum')->putJson("/api/v1/users/{$other->id}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_any_authenticated_user_can_view_their_own_profile_via_users_show(): void
    {
        $manager = User::factory()->hotelManager()->create();

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/users/{$manager->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $manager->id);
    }

    public function test_user_cannot_view_another_users_profile_without_permission(): void
    {
        $manager = User::factory()->hotelManager()->create();
        $other = User::factory()->reception()->create();

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/users/{$other->id}")
            ->assertStatus(403);
    }

    public function test_creating_a_user_validates_required_fields_and_unique_email(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $existing = User::factory()->reception()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/users', [
            'email' => $existing->email,
            'password' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role_id']);
    }

    public function test_deactivating_a_user_revokes_their_existing_sessions(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reception = User::factory()->reception()->create();
        $token = $reception->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/users/{$reception->id}", ['is_active' => false])
            ->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame(0, $reception->tokens()->count());
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_a_token_of_an_account_deactivated_outside_the_api_no_longer_authenticates(): void
    {
        $reception = User::factory()->reception()->create();
        $token = $reception->createToken('api')->plainTextToken;

        $reception->forceFill(['is_active' => false])->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_changing_a_users_password_revokes_their_existing_sessions(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reception = User::factory()->reception()->create();
        $reception->createToken('api');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/users/{$reception->id}", [
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ])
            ->assertOk();

        $this->assertSame(0, $reception->tokens()->count());
    }

    public function test_a_plain_profile_update_keeps_the_users_sessions(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reception = User::factory()->reception()->create();
        $reception->createToken('api');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/users/{$reception->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame(1, $reception->tokens()->count());
    }
}
