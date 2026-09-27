<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Services\ServiceOrderService;
use Tests\TestCase;

class GuestServiceOrderTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function reservationFor(Guest $guest, string $status = Reservation::STATUS_CHECKED_IN): array
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => $status,
        ]);

        return [$hotel, $reservation];
    }

    public function test_unauthenticated_is_401(): void
    {
        $reservation = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/service-orders")->assertStatus(401);
    }

    public function test_guest_creates_an_order_with_server_derived_price(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id, 'price' => '12.50', 'currency' => 'USD']);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/service-orders", [
            'service_id' => $service->id,
            'quantity' => 3,
            'unit_price_snapshot' => '0.01',
            'total_amount' => '0.03',
            'hotel_id' => Hotel::factory()->create()->id,
            'status' => ServiceOrder::STATUS_FULFILLED,
        ])->assertStatus(201)
            ->assertJsonPath('data.unit_price_snapshot', '12.50')
            ->assertJsonPath('data.total_amount', '37.50')
            ->assertJsonPath('data.hotel_id', $hotel->id)
            ->assertJsonPath('data.status', ServiceOrder::STATUS_REQUESTED);
    }

    public function test_guest_lists_and_reads_only_their_own_orders(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);
        $order = ServiceOrder::factory()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'service_id' => $service->id,
        ]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/service-orders")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.id', $order->id);
    }

    public function test_guest_cannot_reach_another_guests_service_orders(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$other->id}/service-orders")->assertStatus(404);
        $this->postJson("/api/v1/guest/reservations/{$other->id}/service-orders", ['service_id' => 1, 'quantity' => 1])
            ->assertStatus(404);
    }

    private function orderFor(Hotel $hotel, Reservation $reservation, string $status): ServiceOrder
    {
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id, 'price' => '20.00', 'currency' => 'USD']);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/service-orders", [
            'service_id' => $service->id,
            'quantity' => 1,
        ])->assertStatus(201);

        $order = ServiceOrder::query()->latest('id')->firstOrFail();

        if ($status !== ServiceOrder::STATUS_REQUESTED) {
            app(ServiceOrderService::class)->transition($order, ServiceOrder::STATUS_CONFIRMED);
            if ($status === ServiceOrder::STATUS_FULFILLED) {
                app(ServiceOrderService::class)->transition($order, ServiceOrder::STATUS_FULFILLED);
            }
        }

        return $order->fresh();
    }

    public function test_guest_cancels_their_own_requested_order(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $order = $this->orderFor($hotel, $reservation, ServiceOrder::STATUS_REQUESTED);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/cancel", ['reason' => 'No longer needed'])
            ->assertOk()
            ->assertJsonPath('data.status', ServiceOrder::STATUS_CANCELLED);

        $this->assertSame('No longer needed', $order->fresh()->cancellation_reason);
    }

    public function test_cancelling_a_confirmed_order_voids_its_folio_charge(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $order = $this->orderFor($hotel, $reservation, ServiceOrder::STATUS_CONFIRMED);
        $this->assertDatabaseHas('folio_charges', ['source_type' => 'service_order', 'source_id' => $order->id, 'status' => 'posted']);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', ServiceOrder::STATUS_CANCELLED);

        $this->assertDatabaseMissing('folio_charges', ['source_type' => 'service_order', 'source_id' => $order->id, 'status' => 'posted']);
    }

    public function test_a_fulfilled_order_cannot_be_cancelled(): void
    {
        $guest = $this->actingGuest();
        [$hotel, $reservation] = $this->reservationFor($guest);
        $order = $this->orderFor($hotel, $reservation, ServiceOrder::STATUS_FULFILLED);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/cancel")
            ->assertStatus(422);

        $this->assertSame(ServiceOrder::STATUS_FULFILLED, $order->fresh()->status);
    }

    public function test_guest_cannot_cancel_another_guests_order(): void
    {
        $owner = Guest::factory()->create();
        [$hotel, $reservation] = $this->reservationFor($owner);
        $this->withToken($owner->createToken('guest-api')->plainTextToken);
        $order = $this->orderFor($hotel, $reservation, ServiceOrder::STATUS_REQUESTED);

        $intruder = Guest::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($intruder->createToken('guest-api')->plainTextToken);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/service-orders/{$order->id}/cancel")
            ->assertStatus(404);

        $this->assertSame(ServiceOrder::STATUS_REQUESTED, $order->fresh()->status);
    }
}
