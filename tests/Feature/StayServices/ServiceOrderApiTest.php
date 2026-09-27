<?php

namespace Tests\Feature\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceOrder;
use Tests\TestCase;

class ServiceOrderApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function reservation(?Hotel $hotel = null, string $status = Reservation::STATUS_CHECKED_IN): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => $status,
        ]);
    }

    private function service(Hotel $hotel, string $price = '30.00', bool $active = true): HotelService
    {
        return HotelService::factory()->create(['hotel_id' => $hotel->id, 'price' => $price, 'is_active' => $active, 'currency' => 'USD']);
    }

    public function test_create_derives_price_and_total_server_side(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation($hotel);
        $service = $this->service($hotel, '12.50');

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders", [
                'service_id' => $service->id,
                'quantity' => 4,
                'unit_price_snapshot' => '0.01',
                'total_amount' => '0.04',
                'hotel_id' => Hotel::factory()->create()->id,
                'status' => ServiceOrder::STATUS_FULFILLED,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.unit_price_snapshot', '12.50')
            ->assertJsonPath('data.total_amount', '50.00')
            ->assertJsonPath('data.hotel_id', $hotel->id)
            ->assertJsonPath('data.status', ServiceOrder::STATUS_REQUESTED);
    }

    public function test_quantity_must_be_a_positive_integer_within_the_cap(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation($hotel);
        $service = $this->service($hotel);

        foreach ([0, -1, 1001] as $bad) {
            $this->actingAs($this->owner(), 'sanctum')
                ->postJson("/api/v1/reservations/{$reservation->id}/service-orders", ['service_id' => $service->id, 'quantity' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors(['quantity']);
        }
    }

    public function test_inactive_service_is_a_422(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation($hotel);
        $service = $this->service($hotel, '10.00', active: false);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders", ['service_id' => $service->id, 'quantity' => 1])
            ->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_service_from_another_hotel_is_a_422(): void
    {
        $reservation = $this->reservation();
        $service = $this->service(Hotel::factory()->create());

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders", ['service_id' => $service->id, 'quantity' => 1])
            ->assertStatus(422);
    }

    public function test_reservation_not_in_stay_is_a_422(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation($hotel, Reservation::STATUS_VERIFIED);
        $service = $this->service($hotel);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders", ['service_id' => $service->id, 'quantity' => 1])
            ->assertStatus(422);
        $this->assertDatabaseCount('service_orders', 0);
    }

    public function test_confirm_transition_posts_a_folio_charge(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation($hotel);
        $order = ServiceOrder::factory()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id,
            'service_id' => $this->service($hotel, '20.00')->id,
            'quantity' => 2, 'unit_price_snapshot' => '20.00', 'total_amount' => '40.00',
        ]);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders/{$order->id}/transition", ['target_status' => 'confirmed'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertSame(1, FolioCharge::where('source_id', $order->id)->count());
        $this->assertSame('40.00', FolioCharge::sole()->total_amount);
    }

    public function test_illegal_transition_is_a_422(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation($hotel);
        $order = ServiceOrder::factory()->create(['reservation_id' => $reservation->id, 'hotel_id' => $hotel->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders/{$order->id}/transition", ['target_status' => 'fulfilled'])
            ->assertStatus(422);
    }

    public function test_transition_target_status_is_validated(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation($hotel);
        $order = ServiceOrder::factory()->create(['reservation_id' => $reservation->id, 'hotel_id' => $hotel->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders/{$order->id}/transition", ['target_status' => 'requested'])
            ->assertStatus(422)->assertJsonValidationErrors(['target_status']);
    }

    public function test_an_order_from_another_reservation_is_a_404(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation($hotel);
        $foreignOrder = ServiceOrder::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/service-orders/{$foreignOrder->id}")
            ->assertStatus(404);
    }

    public function test_cross_hotel_reservation_is_a_404_for_a_manager(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);
        $reservation = $this->reservation($other);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/service-orders")
            ->assertStatus(404);
    }

    public function test_unauthenticated_is_401(): void
    {
        $reservation = $this->reservation();
        $this->postJson("/api/v1/reservations/{$reservation->id}/service-orders", [])->assertStatus(401);
    }
}
