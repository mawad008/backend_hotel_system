<?php

namespace Tests\Feature;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class ApiResponseStructureTest extends TestCase
{
    public function test_successful_collection_response_follows_the_standard_envelope(): void
    {
        $owner = User::factory()->groupOwner()->create();
        HotelGroup::factory()->count(2)->create();

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/hotel-groups');

        $response->assertOk()->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => ['current_page', 'per_page', 'total'],
        ]);

        $this->assertTrue($response->json('success'));
    }

    public function test_successful_single_resource_response_follows_the_standard_envelope(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $group = HotelGroup::factory()->create();

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/v1/hotel-groups/{$group->id}");

        $response->assertOk()->assertJsonStructure(['success', 'message', 'data']);
        $this->assertTrue($response->json('success'));
    }

    public function test_validation_error_response_follows_the_standard_error_envelope(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/hotel-groups', []);

        $response->assertStatus(422)->assertJsonStructure(['success', 'message', 'errors']);
        $this->assertFalse($response->json('success'));
    }

    public function test_forbidden_response_follows_the_standard_error_envelope(): void
    {
        $manager = User::factory()->hotelManager()->create();

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/hotel-groups');

        $response->assertStatus(403)->assertJsonStructure(['success', 'message']);
        $this->assertFalse($response->json('success'));
    }

    public function test_unauthenticated_response_follows_the_standard_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/hotel-groups');

        $response->assertStatus(401)->assertJsonStructure(['success', 'message']);
        $this->assertFalse($response->json('success'));
    }

    public function test_unknown_route_returns_a_json_404(): void
    {
        $response = $this->getJson('/api/v1/this-route-does-not-exist');

        $response->assertStatus(404)->assertJsonStructure(['success', 'message']);
        $this->assertFalse($response->json('success'));
    }
}
