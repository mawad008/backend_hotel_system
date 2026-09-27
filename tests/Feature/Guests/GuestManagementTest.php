<?php

namespace Tests\Feature\Guests;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class GuestManagementTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    public function test_staff_lists_the_guest_directory_with_reservation_counts(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $guest = Guest::factory()->create(['name' => 'Layla Hassan']);
        Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
        ]);
        Guest::factory()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/guests')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $row = collect($response->json('data'))->firstWhere('id', $guest->id);
        $this->assertSame(1, $row['reservations_count']);
    }

    public function test_staff_can_search_guests_by_name_email_or_phone(): void
    {
        $owner = User::factory()->groupOwner()->create();
        Guest::factory()->create(['name' => 'Layla Hassan', 'email' => 'layla@example.com']);
        Guest::factory()->create(['name' => 'Omar Youssef', 'email' => 'omar@example.com']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/guests?search=Layla')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Layla Hassan');
    }

    public function test_staff_views_a_single_guest(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $guest = Guest::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/guests/{$guest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $guest->id);
    }

    public function test_unknown_guest_returns_404(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/guests/999999')
            ->assertStatus(404);
    }

    public function test_hotel_manager_only_sees_the_guests_reservations_within_their_own_hotel_access(): void
    {
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $manager = $this->hotelManagerFor($hotelA);

        $guest = Guest::factory()->create();
        Reservation::factory()->create([
            'hotel_id' => $hotelA->id,
            'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelA->id])->id,
            'guest_id' => $guest->id,
        ]);
        Reservation::factory()->create([
            'hotel_id' => $hotelB->id,
            'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id,
            'guest_id' => $guest->id,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/guests/{$guest->id}/reservations")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame($hotelA->id, $response->json('data.0.hotel_id'));
    }

    public function test_staff_without_guests_permission_is_forbidden(): void
    {
        $user = User::factory()->guest()->create();
        $guest = Guest::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/guests')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/guests/{$guest->id}")->assertStatus(403);
    }

    public function test_guest_directory_requires_authentication(): void
    {
        $this->getJson('/api/v1/guests')->assertStatus(401);
    }
}
