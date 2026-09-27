<?php

namespace Tests\Feature\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use Tests\TestCase;

class FolioLedgerApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    private function reservationWithCharge(Hotel $hotel, string $amount, ?Guest $guest = null): Reservation
    {
        static $sourceId = 1000;
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_IN_STAY,
            'guest_id' => ($guest ?? Guest::factory()->create())->id,
        ]);
        FolioCharge::factory()->amount($amount, 1)->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'source_id' => $sourceId++,
        ]);

        return $reservation;
    }

    public function test_staff_lists_the_hotel_folio_ledger(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $this->reservationWithCharge($hotel, '50.00');
        $this->reservationWithCharge($hotel, '30.00');

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/folios")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertNotNull($response->json('data.0.totals.charges_total'));
    }

    public function test_outstanding_only_excludes_fully_paid_reservations(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);

        $unpaid = $this->reservationWithCharge($hotel, '40.00');

        $paid = $this->reservationWithCharge($hotel, '20.00');
        $payment = Payment::factory()->create([
            'reservation_id' => $paid->id, 'hotel_id' => $hotel->id,
            'status' => Payment::STATUS_CAPTURED, 'amount' => '20.00', 'currency' => 'USD',
        ]);
        PaymentTransaction::factory()->create([
            'payment_id' => $payment->id, 'type' => PaymentTransaction::TYPE_CAPTURE,
            'status' => PaymentTransaction::STATUS_SUCCEEDED, 'amount' => '20.00',
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/folios?outstanding=1")
            ->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reservation.id', $unpaid->id)
            ->assertJsonPath('data.0.totals.outstanding_total', '40.00');
    }

    public function test_search_matches_guest_name(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $guest = Guest::factory()->create(['name' => 'Layla Hassan']);
        $target = $this->reservationWithCharge($hotel, '15.00', $guest);
        $this->reservationWithCharge($hotel, '15.00');

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/folios?search=Layla")
            ->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reservation.id', $target->id);
    }

    public function test_reception_can_view_the_ledger(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $this->reservationWithCharge($hotel, '10.00');

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/folios")
            ->assertOk();
    }

    public function test_staff_from_another_hotel_cannot_view_the_ledger(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/folios")
            ->assertStatus(403);
    }

    public function test_ledger_requires_authentication(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);

        $this->getJson("/api/v1/hotels/{$hotel->id}/folios")->assertStatus(401);
    }
}
