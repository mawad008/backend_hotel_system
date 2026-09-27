<?php

namespace Tests\Feature\Location;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Tests\TestCase;

class CountryApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function viewer(): User
    {
        // Reception holds locations.view but not locations.manage.
        return User::factory()->reception()->create();
    }

    // ── List / search / pagination ─────────────────────────────────

    public function test_list_countries_is_paginated(): void
    {
        Country::factory()->count(3)->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/countries')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_list_countries_can_be_searched(): void
    {
        Country::factory()->create(['name_en' => 'Egypt', 'name_ar' => 'مصر', 'code' => 'EG']);
        Country::factory()->create(['name_en' => 'Saudi Arabia', 'name_ar' => 'السعودية', 'code' => 'SA']);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/countries?search=egy')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'EG');
    }

    public function test_list_countries_can_be_filtered_by_active_status(): void
    {
        Country::factory()->create(['is_active' => true]);
        Country::factory()->inactive()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/countries?is_active=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_country_resource_exposes_cities_count(): void
    {
        $country = Country::factory()->create();
        City::factory()->count(2)->create(['country_id' => $country->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/countries/{$country->id}")
            ->assertOk()
            ->assertJsonPath('data.cities_count', 2);
    }

    // ── Create / update ─────────────────────────────────────────────

    public function test_manager_user_can_create_a_country_and_it_is_audited(): void
    {
        $response = $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/countries', [
            'name_en' => 'Egypt',
            'name_ar' => 'مصر',
            'code' => 'eg',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name_en', 'Egypt')
            ->assertJsonPath('data.code', 'EG') // normalized to upper-case
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('countries', ['name_en' => 'Egypt', 'code' => 'EG']);
        $this->assertNotNull(AuditLog::where('action', 'country.created')->first());
    }

    public function test_create_country_rejects_a_duplicate_code(): void
    {
        Country::factory()->create(['code' => 'EG']);

        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/countries', [
            'name_en' => 'Egypt', 'name_ar' => 'مصر', 'code' => 'eg',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_create_country_validates_required_fields(): void
    {
        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/countries', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_en', 'name_ar', 'code']);
    }

    public function test_update_country(): void
    {
        $country = Country::factory()->create(['name_en' => 'Old']);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/countries/{$country->id}", ['name_en' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name_en', 'New');

        $this->assertNotNull(AuditLog::where('action', 'country.updated')->first());
    }

    public function test_is_active_cannot_be_changed_through_the_generic_update_endpoint(): void
    {
        $country = Country::factory()->create(['is_active' => true]);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/countries/{$country->id}", ['is_active' => false, 'name_en' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.name_en', 'Renamed');
    }

    // ── Activate / deactivate ──────────────────────────────────────

    public function test_activate_and_deactivate_endpoints(): void
    {
        $country = Country::factory()->create(['is_active' => true]);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/countries/{$country->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertNotNull(AuditLog::where('action', 'country.deactivated')->first());

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/countries/{$country->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertNotNull(AuditLog::where('action', 'country.activated')->first());
    }

    // ── Delete behavior ────────────────────────────────────────────

    public function test_country_with_no_references_can_be_deleted(): void
    {
        $country = Country::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/countries/{$country->id}")
            ->assertOk();

        $this->assertDatabaseMissing('countries', ['id' => $country->id]);
        $this->assertNotNull(AuditLog::where('action', 'country.deleted')->first());
    }

    public function test_country_with_cities_cannot_be_deleted(): void
    {
        $country = Country::factory()->create();
        City::factory()->create(['country_id' => $country->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/countries/{$country->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('countries', ['id' => $country->id]);
    }

    public function test_country_referenced_by_a_hotel_cannot_be_deleted(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/countries/{$hotel->country_id}")
            ->assertStatus(422);
    }

    // ── Authorization ──────────────────────────────────────────────

    public function test_viewer_can_read_but_not_write(): void
    {
        $viewer = $this->viewer();
        $country = Country::factory()->create();

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/countries')->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/countries/{$country->id}")->assertOk();

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/countries', [
            'name_en' => 'X', 'name_ar' => 'X', 'code' => 'XX',
        ])->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')->putJson("/api/v1/countries/{$country->id}", ['name_en' => 'Y'])->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')->patchJson("/api/v1/countries/{$country->id}/deactivate")->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')->deleteJson("/api/v1/countries/{$country->id}")->assertStatus(403);
    }

    public function test_user_without_locations_view_has_no_access(): void
    {
        $guest = User::factory()->guest()->create();
        $country = Country::factory()->create();

        $this->actingAs($guest, 'sanctum')->getJson('/api/v1/countries')->assertStatus(403);
        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/countries/{$country->id}")->assertStatus(403);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/countries')->assertStatus(401);
    }

    public function test_missing_country_is_a_standard_404(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/countries/999999')
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }
}
