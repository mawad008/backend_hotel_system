<?php

namespace Tests\Feature\Hotel;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\Permission;
use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HotelAccessTest extends TestCase
{
    public function test_group_owner_sees_every_hotel_without_explicit_assignment(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $group = HotelGroup::factory()->create();
        Hotel::factory()->count(3)->create(['hotel_group_id' => $group->id]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/hotels');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_hotel_manager_only_sees_assigned_hotels(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/hotels');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$hotelA->id], $ids);
        $this->assertNotContains($hotelB->id, $ids);
    }

    public function test_hotel_manager_can_view_an_assigned_hotel(): void
    {
        $group = HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $hotel->id);
    }

    public function test_hotel_manager_is_denied_access_to_an_unassigned_hotel(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        // Cross-hotel access denial: a manager's token must never read
        // another hotel's data, even one owned by the same group.
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}")
            ->assertStatus(403);
    }

    public function test_hotel_manager_may_be_assigned_to_multiple_hotels(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelC = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach([$hotelA->id, $hotelB->id]);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/hotels');

        $ids = array_column($response->json('data'), 'id');
        sort($ids);
        $this->assertSame([$hotelA->id, $hotelB->id], $ids);

        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/hotels/{$hotelC->id}")->assertStatus(403);
    }

    public function test_hotel_manager_cannot_create_or_update_hotels(): void
    {
        $group = HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/hotels', [
                'hotel_group_id' => $group->id,
                'name' => 'New Hotel',
                'slug' => 'new-hotel',
                'deposit_percentage' => 10,
            ])
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", ['name' => 'Renamed'])
            ->assertStatus(403);
    }

    /**
     * Regression test for the HotelPolicy::update() authorization gap found
     * during Hotel module hardening: update() checked only `hotels.manage`,
     * not the hotel-scope resolved from the user's own access records (view()
     * and manageMedia() both already did). Today only Group Owner holds
     * `hotels.manage`, so this constructs a non-Group-Owner role that holds
     * it to actually exercise the scope check in isolation.
     */
    public function test_a_non_group_owner_with_hotels_manage_cannot_update_an_unassigned_hotel(): void
    {
        $role = Role::factory()->create(['slug' => 'test-hotel-editor']);
        $role->permissions()->sync(Permission::query()->whereIn('slug', ['hotels.view', 'hotels.manage'])->pluck('id'));

        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $editor = User::factory()->create(['role_id' => $role->id]);
        $editor->hotels()->attach($hotelA);

        $this->actingAs($editor, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotelA->id}", ['name' => 'Renamed A'])
            ->assertOk();

        $this->actingAs($editor, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotelB->id}", ['name' => 'Renamed B'])
            ->assertStatus(403);
    }

    /**
     * A creator without the Group Owner bypass must not be locked out of
     * the hotel they just created — otherwise the Dashboard's "upload
     * media right after create" flow (and simply reopening the hotel)
     * would 403 immediately after a successful POST /hotels.
     */
    public function test_a_non_group_owner_creator_is_granted_access_to_the_hotel_they_just_created(): void
    {
        Storage::fake('public');

        $role = Role::factory()->create(['slug' => 'test-hotel-creator']);
        $role->permissions()->sync(Permission::query()->whereIn('slug', ['hotels.view', 'hotels.manage'])->pluck('id'));

        $group = HotelGroup::factory()->create();
        $country = Country::factory()->create();
        $city = City::factory()->create(['country_id' => $country->id]);
        $creator = User::factory()->create(['role_id' => $role->id]);

        $created = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/hotels', [
                'hotel_group_id' => $group->id,
                'name' => 'Creator Access Hotel',
                'slug' => 'creator-access-hotel',
                'deposit_percentage' => 10,
                'country_id' => $country->id,
                'city_id' => $city->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($creator, 'sanctum')
            ->getJson("/api/v1/hotels/{$created}")
            ->assertOk();

        $this->actingAs($creator, 'sanctum')
            ->postJson("/api/v1/hotels/{$created}/media", [
                'collection' => 'logo',
                'image' => UploadedFile::fake()->image('logo.jpg'),
            ])
            ->assertCreated();
    }

    public function test_reception_can_view_only_their_assigned_hotel(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotelA);

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelA->id}")
            ->assertOk();

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}")
            ->assertStatus(403);
    }

    public function test_reception_cannot_create_or_update_hotels(): void
    {
        $group = HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->postJson('/api/v1/hotels', [
                'hotel_group_id' => $group->id,
                'name' => 'New Hotel',
                'slug' => 'new-hotel-2',
                'deposit_percentage' => 10,
            ])
            ->assertStatus(403);

        $this->actingAs($reception, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", ['name' => 'Renamed'])
            ->assertStatus(403);
    }

    public function test_guest_role_has_no_hotel_access(): void
    {
        $group = HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $guest = User::factory()->guest()->create();

        $this->actingAs($guest, 'sanctum')->getJson('/api/v1/hotels')->assertStatus(403);
        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}")->assertStatus(403);
    }

    public function test_hotel_id_supplied_in_the_request_body_cannot_widen_access(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        // Attempting to view Hotel B directly by id is denied regardless
        // of any hotel_id the client might otherwise try to smuggle in —
        // scope is resolved only from the manager's own stored access.
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}?hotel_id={$hotelA->id}")
            ->assertStatus(403);
    }

    public function test_viewing_a_nonexistent_hotel_returns_not_found(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/hotels/999999')
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    /**
     * Regression test for Phase 2D bug #2: implicit route model binding
     * on a genuinely missing id used to leak Eloquent's raw exception
     * message instead of the standard localized not-found message. This
     * is the non-Inventory resource confirming the bootstrap/app.php fix
     * is general — not specific to Room/RoomType.
     */
    public function test_implicit_route_model_binding_404_returns_the_standard_message_without_leaking_internals(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/hotels/999999')
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => 'The requested resource was not found.',
            ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('Hotel', $body, 'Response leaked the model class name.');
        $this->assertStringNotContainsString('App\\Domain', $body);
        $this->assertStringNotContainsString('No query results', $body);
    }
}
