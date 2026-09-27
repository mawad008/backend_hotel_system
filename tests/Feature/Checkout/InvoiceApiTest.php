<?php

namespace Tests\Feature\Checkout;

use App\Domain\Checkout\Models\Invoice;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceOrder;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function checkedOutReservation(?Hotel $hotel = null): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            // price_snapshot 0.00 so the invoice subtotal here is exactly the
            // service charge (accommodation is covered in CheckoutAccountingTest).
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => Reservation::STATUS_IN_STAY,
            'price_snapshot' => '0.00',
        ]);
        Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'amount' => '0.00', 'currency' => 'USD',
        ]);

        $service = HotelService::factory()->create(['hotel_id' => $hotel->id, 'price' => '25.00', 'currency' => 'USD']);
        $order = ServiceOrder::factory()->confirmed()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'service_id' => $service->id,
            'quantity' => 2, 'unit_price_snapshot' => '25.00', 'currency_snapshot' => 'USD', 'total_amount' => '50.00',
        ]);
        FolioCharge::factory()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id,
            'source_type' => FolioCharge::SOURCE_SERVICE_ORDER, 'source_id' => $order->id,
            'description' => 'Spa x2', 'quantity' => 2, 'unit_amount' => '25.00', 'total_amount' => '50.00',
            'currency' => 'USD', 'status' => FolioCharge::STATUS_POSTED,
        ]);

        $this->actingAs($this->owner(), 'sanctum')->withHeaders(['X-Payment-Simulate' => 'success'])
            ->postJson("/api/v1/reservations/{$reservation->id}/checkout")->assertOk();

        return $reservation->fresh();
    }

    public function test_invoice_retrieval_shape(): void
    {
        $reservation = $this->checkedOutReservation();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/invoice")
            ->assertOk()
            ->assertJsonPath('data.status', Invoice::STATUS_ISSUED)
            ->assertJsonPath('data.subtotal', '50.00')
            ->assertJsonPath('data.payments_total', '50.00')
            ->assertJsonPath('data.outstanding_total', '0.00')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.total_amount', '50.00')
            ->assertJsonStructure(['data' => ['invoice_number', 'status', 'currency', 'items', 'issued_at']]);
    }

    public function test_invoice_is_404_before_checkout(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => Reservation::STATUS_IN_STAY,
        ]);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/invoice")
            ->assertStatus(404);
    }

    public function test_invoice_response_has_no_payment_secret(): void
    {
        $reservation = $this->checkedOutReservation();
        $body = $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/invoice")->getContent();

        foreach (['provider', 'card', 'cvv', 'created_by_user_id', 'SQLSTATE'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_cross_hotel_invoice_is_a_404(): void
    {
        $reservation = $this->checkedOutReservation(Hotel::factory()->create());
        $assigned = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/invoice")
            ->assertStatus(404);
    }
}
