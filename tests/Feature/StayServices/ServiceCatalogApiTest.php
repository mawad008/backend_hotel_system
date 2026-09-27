<?php

namespace Tests\Feature\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceCategory;
use Tests\TestCase;

class ServiceCatalogApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function manager(Hotel $hotel): User
    {
        $user = User::factory()->hotelManager()->create();
        $user->hotels()->attach($hotel);

        return $user;
    }

    // ── Categories ─────────────────────────────────────────────────

    public function test_create_and_list_categories(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/service-categories", ['name' => 'Spa', 'description' => 'wellness'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Spa')
            ->assertJsonPath('data.hotel_id', $hotel->id)
            ->assertJsonPath('data.is_active', true);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/service-categories")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_duplicate_category_name_per_hotel_is_a_422(): void
    {
        $hotel = Hotel::factory()->create();
        ServiceCategory::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Spa']);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/service-categories", ['name' => 'Spa'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_category_activate_deactivate(): void
    {
        $hotel = Hotel::factory()->create();
        $category = ServiceCategory::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/service-categories/{$category->id}/deactivate")
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_cross_hotel_category_is_a_404(): void
    {
        $hotel = Hotel::factory()->create();
        $other = ServiceCategory::factory()->create(['hotel_id' => Hotel::factory()->create()->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/service-categories/{$other->id}", ['name' => 'x'])
            ->assertStatus(404);
    }

    // ── Services ───────────────────────────────────────────────────

    public function test_create_service_ignores_client_hotel_and_activation(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->manager($hotel), 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/services", [
                'name' => 'Airport transfer',
                'price' => '45.50',
                'currency' => 'USD',
                'hotel_id' => Hotel::factory()->create()->id,
                'is_active' => false,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.hotel_id', $hotel->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.price', '45.50');
    }

    public function test_service_price_must_be_within_bounds_and_two_decimals(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->manager($hotel), 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/services", ['name' => 'A', 'price' => '10.999'])
            ->assertStatus(422)->assertJsonValidationErrors(['price']);

        $this->actingAs($this->manager($hotel), 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/services", ['name' => 'B', 'price' => '9999999'])
            ->assertStatus(422)->assertJsonValidationErrors(['price']);
    }

    public function test_service_category_must_belong_to_the_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $foreign = ServiceCategory::factory()->create(['hotel_id' => Hotel::factory()->create()->id]);

        $this->actingAs($this->manager($hotel), 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/services", [
                'name' => 'C', 'price' => '10.00', 'service_category_id' => $foreign->id,
            ])
            ->assertStatus(422)->assertJsonValidationErrors(['service_category_id']);
    }

    public function test_active_filter_on_the_list(): void
    {
        $hotel = Hotel::factory()->create();
        HotelService::factory()->count(2)->create(['hotel_id' => $hotel->id, 'is_active' => true]);
        HotelService::factory()->create(['hotel_id' => $hotel->id, 'is_active' => false]);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/services?active=1")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_there_is_no_service_delete_route(): void
    {
        $hotel = Hotel::factory()->create();
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/hotels/{$hotel->id}/services/{$service->id}")
            ->assertStatus(405);
    }

    public function test_response_never_leaks_internals(): void
    {
        $hotel = Hotel::factory()->create();
        $body = $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/services", ['name' => 'X', 'price' => '10.00'])
            ->getContent();

        foreach (['SQLSTATE', '.php:', 'Eloquent'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }
}
