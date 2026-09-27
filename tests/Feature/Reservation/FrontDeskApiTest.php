<?php

namespace Tests\Feature\Reservation;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class FrontDeskApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    private function reservationFor(Hotel $hotel, array $attrs): Reservation
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create(array_merge([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
        ], $attrs));
    }

    public function test_staff_lists_todays_arrivals(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $today = now()->toDateString();
        $this->reservationFor($hotel, ['status' => Reservation::STATUS_VERIFIED, 'check_in' => $today, 'check_out' => now()->addDays(2)->toDateString()]);
        $this->reservationFor($hotel, ['status' => Reservation::STATUS_DEPOSIT_HELD, 'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(3)->toDateString()]);
        $this->reservationFor($hotel, ['status' => Reservation::STATUS_CANCELLED, 'check_in' => $today, 'check_out' => now()->addDays(2)->toDateString()]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/arrivals")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertStringStartsWith($today, $response->json('data.0.check_in'));
    }

    public function test_staff_can_query_arrivals_for_a_specific_date(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $target = now()->addDays(5)->toDateString();
        $this->reservationFor($hotel, ['status' => Reservation::STATUS_VERIFIED, 'check_in' => $target, 'check_out' => now()->addDays(7)->toDateString()]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/arrivals?date={$target}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_staff_lists_todays_departures(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $today = now()->toDateString();
        $this->reservationFor($hotel, ['status' => Reservation::STATUS_IN_STAY, 'check_in' => now()->subDays(2)->toDateString(), 'check_out' => $today]);
        $this->reservationFor($hotel, ['status' => Reservation::STATUS_IN_STAY, 'check_in' => now()->subDay()->toDateString(), 'check_out' => now()->addDay()->toDateString()]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/departures")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertStringStartsWith($today, $response->json('data.0.check_out'));
    }

    public function test_staff_lists_the_in_house_guests(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $this->reservationFor($hotel, ['status' => Reservation::STATUS_IN_STAY, 'check_in' => now()->subDay()->toDateString(), 'check_out' => now()->addDay()->toDateString()]);
        $this->reservationFor($hotel, ['status' => Reservation::STATUS_CHECKOUT_IN_PROGRESS, 'check_in' => now()->subDays(3)->toDateString(), 'check_out' => now()->toDateString()]);
        $this->reservationFor($hotel, ['status' => Reservation::STATUS_CHECKED_OUT, 'check_in' => now()->subDays(5)->toDateString(), 'check_out' => now()->subDays(2)->toDateString()]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/in-house")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
    }

    public function test_reception_can_view_the_front_desk_lists(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/arrivals")->assertOk();
        $this->actingAs($reception, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/departures")->assertOk();
        $this->actingAs($reception, 'sanctum')->getJson("/api/v1/hotels/{$hotel->id}/in-house")->assertOk();
    }

    public function test_staff_from_another_hotel_cannot_view_the_front_desk_lists(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/in-house")
            ->assertStatus(403);
    }

    public function test_staff_without_reservations_permission_is_forbidden(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $user = User::factory()->guest()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/arrivals")
            ->assertStatus(403);
    }
}
