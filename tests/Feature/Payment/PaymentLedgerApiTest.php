<?php

namespace Tests\Feature\Payment;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class PaymentLedgerApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    private function paymentFor(Hotel $hotel, string $status): Payment
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        return Payment::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $hotel->id,
            'status' => $status,
        ]);
    }

    public function test_staff_lists_the_hotel_payments_ledger(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $this->paymentFor($hotel, Payment::STATUS_HOLD_ACTIVE);
        $this->paymentFor($hotel, Payment::STATUS_SETTLED);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/payments")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertNotNull($response->json('data.0.hotel_id'));
    }

    public function test_staff_can_filter_by_status(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $this->paymentFor($hotel, Payment::STATUS_HOLD_ACTIVE);
        $this->paymentFor($hotel, Payment::STATUS_SETTLED);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/payments?status=".Payment::STATUS_SETTLED)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', Payment::STATUS_SETTLED);
    }

    public function test_reception_can_view_the_ledger(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $this->paymentFor($hotel, Payment::STATUS_HOLD_ACTIVE);

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/payments")
            ->assertOk();
    }

    public function test_staff_from_another_hotel_cannot_view_the_ledger(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/payments")
            ->assertStatus(403);
    }

    public function test_staff_without_payments_permission_is_forbidden(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $user = User::factory()->guest()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/payments")
            ->assertStatus(403);
    }

    public function test_ledger_requires_authentication(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);

        $this->getJson("/api/v1/hotels/{$hotel->id}/payments")->assertStatus(401);
    }
}
