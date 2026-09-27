<?php

namespace Tests\Feature\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\HotelService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StayServicesAuthorizationTest extends TestCase
{
    private function user(string $factory, Hotel $hotel): User
    {
        $user = User::factory()->{$factory}()->create();

        // Guest-role staff users are never assigned a hotel (guest auth does
        // not exist in this MVP) — so a guest hits the reservation-scope 404
        // before any policy check, exactly like every other reservation
        // sub-resource.
        if ($factory !== 'guest') {
            $user->hotels()->attach($hotel);
        }

        return $user;
    }

    /**
     * @return array<string, array{string, bool, bool}>
     *                                                  factory => [catalog-view, catalog-manage]
     */
    public static function catalogMatrix(): array
    {
        return [
            'group owner' => ['groupOwner', true, true],
            'hotel manager' => ['hotelManager', true, true],
            'reception' => ['reception', true, false],
            'guest' => ['guest', false, false],
        ];
    }

    #[DataProvider('catalogMatrix')]
    public function test_catalog_permissions(string $factory, bool $canView, bool $canManage): void
    {
        $hotel = Hotel::factory()->create();
        $user = $this->user($factory, $hotel);
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/services")
            ->assertStatus($canView ? 200 : 403);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/services", ['name' => 'New '.$factory, 'price' => '10.00'])
            ->assertStatus($canManage ? 201 : 403);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/services/{$service->id}/deactivate")
            ->assertStatus($canManage ? 200 : 403);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function orderMatrix(): array
    {
        return [
            'group owner' => ['groupOwner', true],
            'hotel manager' => ['hotelManager', true],
            'reception' => ['reception', true],
            'guest' => ['guest', false],
        ];
    }

    #[DataProvider('orderMatrix')]
    public function test_service_order_and_folio_permissions(string $factory, bool $allowed): void
    {
        $hotel = Hotel::factory()->create();
        $user = $this->user($factory, $hotel);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => Reservation::STATUS_CHECKED_IN,
        ]);
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id, 'price' => '10.00']);

        // Guest role users get a scope 404 (no permission => not even the
        // reservation resolves for them), everyone else 200/201.
        $expectedList = $allowed ? 200 : 404;
        $expectedCreate = $allowed ? 201 : 404;

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/service-orders")
            ->assertStatus($expectedList);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders", ['service_id' => $service->id, 'quantity' => 1])
            ->assertStatus($expectedCreate);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/folio")
            ->assertStatus($allowed ? 200 : 404);
    }

    public function test_reception_cannot_configure_the_catalog_but_can_take_an_order(): void
    {
        $hotel = Hotel::factory()->create();
        $reception = $this->user('reception', $hotel);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => Reservation::STATUS_IN_STAY,
        ]);
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id, 'price' => '10.00']);

        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/hotels/{$hotel->id}/services", ['name' => 'Z', 'price' => '1.00'])
            ->assertStatus(403);

        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders", ['service_id' => $service->id, 'quantity' => 1])
            ->assertStatus(201);
    }

    public function test_client_cannot_forge_hotel_scope_via_body(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $manager = $this->user('hotelManager', $hotelA);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotelB->id, 'room_type_id' => $roomType->id, 'status' => Reservation::STATUS_CHECKED_IN,
        ]);

        // manager only has hotelA — passing hotel_id=hotelA must not grant
        // access to a hotelB reservation.
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/service-orders", [
                'service_id' => 1, 'quantity' => 1, 'hotel_id' => $hotelA->id,
            ])
            ->assertStatus(404);
    }
}
