<?php

namespace Tests\Feature\Checkout;

use App\Domain\Checkout\Models\Invoice;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class InvoiceLedgerApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    private function invoiceFor(Hotel $hotel, string $status): Invoice
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        return Invoice::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $hotel->id,
            'status' => $status,
        ]);
    }

    public function test_staff_lists_the_hotel_invoices_ledger(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $this->invoiceFor($hotel, Invoice::STATUS_DRAFT);
        $this->invoiceFor($hotel, Invoice::STATUS_ISSUED);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/invoices")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertNotNull($response->json('data.0.hotel_id'));
    }

    public function test_staff_can_filter_by_status(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $this->invoiceFor($hotel, Invoice::STATUS_DRAFT);
        $this->invoiceFor($hotel, Invoice::STATUS_ISSUED);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/invoices?status=".Invoice::STATUS_ISSUED)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', Invoice::STATUS_ISSUED);
    }

    public function test_reception_can_view_the_ledger(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $this->invoiceFor($hotel, Invoice::STATUS_ISSUED);

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/invoices")
            ->assertOk();
    }

    public function test_staff_from_another_hotel_cannot_view_the_ledger(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/invoices")
            ->assertStatus(403);
    }

    public function test_staff_without_invoice_permission_is_forbidden(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $user = User::factory()->guest()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/invoices")
            ->assertStatus(403);
    }
}
