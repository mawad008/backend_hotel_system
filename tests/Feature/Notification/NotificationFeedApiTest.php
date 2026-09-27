<?php

namespace Tests\Feature\Notification;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\Notification;
use Tests\TestCase;

class NotificationFeedApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    public function test_staff_lists_the_hotel_wide_feed(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        Notification::factory()->create(['hotel_id' => $hotel->id]);
        Notification::factory()->read()->create(['hotel_id' => $hotel->id]);
        // A different channel row must never appear in the in_app feed.
        Notification::factory()->channel(NotificationChannel::Email)->create(['hotel_id' => $hotel->id]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/notifications")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
    }

    public function test_staff_can_filter_unread_only(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        Notification::factory()->create(['hotel_id' => $hotel->id]);
        Notification::factory()->read()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/notifications?unread=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_read', false);
    }

    public function test_reception_can_view_the_feed(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        Notification::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/notifications")
            ->assertOk();
    }

    public function test_staff_from_another_hotel_cannot_view_the_feed(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/notifications")
            ->assertStatus(403);
    }

    public function test_staff_without_notifications_permission_is_forbidden(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $user = User::factory()->guest()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/notifications")
            ->assertStatus(403);
    }
}
