<?php

namespace Tests\Feature\Checkout;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class SettlementLedgerApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    private function checkoutFor(Hotel $hotel, string $status): Checkout
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        return Checkout::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $hotel->id,
            'status' => $status,
        ]);
    }

    public function test_staff_lists_the_hotel_settlements_ledger(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $this->checkoutFor($hotel, Checkout::STATUS_IN_PROGRESS);
        $this->checkoutFor($hotel, Checkout::STATUS_COMPLETED);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/settlements")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertNotNull($response->json('data.0.hotel_id'));
    }

    public function test_staff_can_filter_by_status(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $this->checkoutFor($hotel, Checkout::STATUS_IN_PROGRESS);
        $this->checkoutFor($hotel, Checkout::STATUS_COMPLETED);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/settlements?status=".Checkout::STATUS_COMPLETED)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', Checkout::STATUS_COMPLETED);
    }

    public function test_reception_can_view_the_ledger(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $this->checkoutFor($hotel, Checkout::STATUS_IN_PROGRESS);

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/settlements")
            ->assertOk();
    }

    public function test_staff_from_another_hotel_cannot_view_the_ledger(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/settlements")
            ->assertStatus(403);
    }

    public function test_staff_without_checkout_permission_is_forbidden(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $user = User::factory()->guest()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/settlements")
            ->assertStatus(403);
    }
}
