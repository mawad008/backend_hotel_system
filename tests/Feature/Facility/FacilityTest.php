<?php

namespace Tests\Feature\Facility;

use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class FacilityTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    public function test_the_catalog_is_seeded_from_the_legacy_amenity_vocabulary(): void
    {
        $this->assertDatabaseHas('facilities', ['key' => 'free_wifi']);
        $this->assertSame(12, Facility::query()->count());
    }

    public function test_staff_with_facilities_manage_can_create_update_and_deactivate_a_facility(): void
    {
        $owner = $this->owner();

        $created = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/facilities', [
            'name_i18n' => ['en' => 'Rooftop Bar', 'ar' => 'بار على السطح'],
            'icon' => 'cocktail',
        ])->assertCreated()
            ->assertJsonPath('data.key', 'rooftop_bar')
            ->assertJsonPath('data.is_active', true);

        $id = $created->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/facilities/{$id}", ['name_i18n' => ['en' => 'Rooftop Lounge']])
            ->assertOk()
            ->assertJsonPath('data.name_i18n.en', 'Rooftop Lounge');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/facilities/{$id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_facility_key_must_be_unique(): void
    {
        Facility::factory()->create(['key' => 'spa_deluxe']);

        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/facilities', [
            'key' => 'spa_deluxe',
            'name_i18n' => ['en' => 'Another Spa'],
        ])->assertStatus(422)->assertJsonValidationErrors(['key']);
    }

    public function test_list_supports_search_and_is_active_filter(): void
    {
        Facility::factory()->create(['key' => 'kids_club', 'name_i18n' => ['en' => 'Kids Club']]);
        Facility::factory()->inactive()->create(['key' => 'valet_parking', 'name_i18n' => ['en' => 'Valet Parking']]);

        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/facilities?search=Kids')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'kids_club');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/facilities?is_active=0')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'valet_parking');
    }

    public function test_all_param_returns_every_active_facility_unpaginated_for_the_picker(): void
    {
        Facility::factory()->inactive()->create();

        $response = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/facilities?all=1');

        $response->assertOk();
        $this->assertTrue(collect($response->json('data'))->every(fn ($f) => $f['is_active'] === true));
    }

    public function test_a_facility_still_assigned_to_a_hotel_cannot_be_deleted(): void
    {
        $facility = Facility::factory()->create();
        $hotel = Hotel::factory()->create();
        $hotel->facilities()->attach($facility);

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/facilities/{$facility->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('facilities', ['id' => $facility->id]);
    }

    public function test_an_unreferenced_facility_can_be_deleted(): void
    {
        $facility = Facility::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/facilities/{$facility->id}")
            ->assertOk();

        $this->assertDatabaseMissing('facilities', ['id' => $facility->id]);
    }

    public function test_hotel_manager_without_facilities_permission_cannot_manage_the_catalog(): void
    {
        $manager = User::factory()->hotelManager()->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/facilities', ['name_i18n' => ['en' => 'Something']])
            ->assertStatus(403);
    }

    public function test_guest_has_no_facility_catalog_access(): void
    {
        $guest = User::factory()->guest()->create();

        $this->actingAs($guest, 'sanctum')
            ->getJson('/api/v1/facilities')
            ->assertStatus(403);
    }

    public function test_assigning_facilities_to_a_hotel_through_the_hotel_update_endpoint(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", [
                'facility_ids' => [$facilityA->id, $facilityB->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.facilities');

        $this->assertDatabaseCount('facility_hotel', 2);
        $this->assertDatabaseHas('facility_hotel', ['hotel_id' => $hotel->id, 'facility_id' => $facilityA->id]);

        // Re-saving with a narrower set replaces (sync), not appends.
        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", ['facility_ids' => [$facilityA->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.facilities');
    }
}
