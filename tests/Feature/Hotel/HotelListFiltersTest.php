<?php

namespace Tests\Feature\Hotel;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

/**
 * The dashboard's Hotel list was previously 100% client-side (the backend
 * accepted no query params at all). These cover the real server-side
 * `search` / `is_active` / `sort` filters added during Hotel module
 * hardening (Phase 7 — list) — always layered on top of the caller's
 * server-resolved hotel scope, never replacing it.
 */
class HotelListFiltersTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    public function test_search_filters_by_name_or_slug(): void
    {
        $group = HotelGroup::factory()->create();
        Hotel::factory()->create(['hotel_group_id' => $group->id, 'name' => 'Nile View', 'slug' => 'nile-view']);
        Hotel::factory()->create(['hotel_group_id' => $group->id, 'name' => 'Desert Oasis', 'slug' => 'desert-oasis']);

        $response = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/hotels?search=Nile');

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Nile View'], $names);
    }

    public function test_is_active_filters_the_list(): void
    {
        $group = HotelGroup::factory()->create();
        Hotel::factory()->create(['hotel_group_id' => $group->id, 'is_active' => true]);
        Hotel::factory()->create(['hotel_group_id' => $group->id, 'is_active' => false]);

        $response = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/hotels?is_active=0');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertFalse($response->json('data.0.is_active'));
    }

    public function test_sort_orders_by_name_descending(): void
    {
        $group = HotelGroup::factory()->create();
        Hotel::factory()->create(['hotel_group_id' => $group->id, 'name' => 'Alpha Hotel']);
        Hotel::factory()->create(['hotel_group_id' => $group->id, 'name' => 'Zeta Hotel']);

        $response = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/hotels?sort=-name');

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Zeta Hotel', 'Alpha Hotel'], $names);
    }

    public function test_hotel_manager_search_stays_within_their_own_scope(): void
    {
        $group = HotelGroup::factory()->create();
        $assigned = Hotel::factory()->create(['hotel_group_id' => $group->id, 'name' => 'Nile Assigned']);
        $unassigned = Hotel::factory()->create(['hotel_group_id' => $group->id, 'name' => 'Nile Unassigned']);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/hotels?search=Nile');

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$assigned->id], $ids);
        $this->assertNotContains($unassigned->id, $ids);
    }
}
