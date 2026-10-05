<?php

namespace Tests\Feature\HotelGroup;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class HotelGroupTest extends TestCase
{
    public function test_group_owner_can_create_a_hotel_group(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/hotel-groups', [
            'name' => 'Acme Hotels',
            'slug' => 'acme-hotels',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'acme-hotels')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('hotel_groups', ['slug' => 'acme-hotels']);
    }

    public function test_group_owner_can_list_and_view_hotel_groups(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $group = HotelGroup::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/hotel-groups')
            ->assertOk()
            ->assertJsonFragment(['id' => $group->id]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/hotel-groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $group->id);
    }

    public function test_group_owner_can_update_a_hotel_group(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $group = HotelGroup::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotel-groups/{$group->id}", ['name' => 'New Name']);

        $response->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('hotel_groups', ['id' => $group->id, 'name' => 'New Name']);
    }

    public function test_a_hotel_group_is_created_and_updated_with_arabic_and_english_names(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $created = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/hotel-groups', [
            'name_i18n' => ['en' => 'Nuzul Hotels', 'ar' => 'فنادق نُزُل'],
            'slug' => 'nuzul-hotels',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Nuzul Hotels')
            ->assertJsonPath('data.name_i18n.ar', 'فنادق نُزُل')
            ->assertJsonPath('data.name_i18n.en', 'Nuzul Hotels');

        $id = $created->json('data.id');

        $this->actingAs($owner, 'sanctum')->putJson("/api/v1/hotel-groups/{$id}", [
            'name_i18n' => ['en' => 'Nuzul Group', 'ar' => 'مجموعة نُزُل'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Nuzul Group')
            ->assertJsonPath('data.name_i18n.ar', 'مجموعة نُزُل');

        // A bare legacy `name` update keeps the English entry in sync and the Arabic one intact.
        $this->actingAs($owner, 'sanctum')->putJson("/api/v1/hotel-groups/{$id}", ['name' => 'Nuzul'])
            ->assertOk()
            ->assertJsonPath('data.name_i18n.en', 'Nuzul')
            ->assertJsonPath('data.name_i18n.ar', 'مجموعة نُزُل');
    }

    public function test_both_language_names_are_required_when_name_i18n_is_sent(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/hotel-groups', [
            'name_i18n' => ['en' => 'Only English'],
            'slug' => 'only-english',
        ])->assertStatus(422)->assertJsonValidationErrors(['name_i18n.ar']);
    }

    public function test_hotel_manager_cannot_manage_hotel_groups(): void
    {
        $manager = User::factory()->hotelManager()->create();
        $group = HotelGroup::factory()->create();

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/hotel-groups')
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/hotel-groups', ['name' => 'X', 'slug' => 'x'])
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/hotel-groups/{$group->id}", ['name' => 'X'])
            ->assertStatus(403);
    }

    public function test_reception_cannot_manage_hotel_groups(): void
    {
        $reception = User::factory()->reception()->create();

        $this->actingAs($reception, 'sanctum')
            ->postJson('/api/v1/hotel-groups', ['name' => 'X', 'slug' => 'x'])
            ->assertStatus(403);
    }

    public function test_creating_a_hotel_group_requires_name_and_unique_slug(): void
    {
        $owner = User::factory()->groupOwner()->create();
        HotelGroup::factory()->create(['slug' => 'taken']);

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/hotel-groups', [
            'slug' => 'taken',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'slug']);
    }
}
