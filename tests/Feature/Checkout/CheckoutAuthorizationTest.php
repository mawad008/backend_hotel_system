<?php

namespace Tests\Feature\Checkout;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutAuthorizationTest extends TestCase
{
    private function reservation(Hotel $hotel): Reservation
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => Reservation::STATUS_IN_STAY,
            'price_snapshot' => '0.00',
        ]);
        Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'amount' => '0.00', 'currency' => 'USD',
        ]);

        return $reservation;
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function roleMatrix(): array
    {
        return [
            'group owner' => ['groupOwner', true],
            'hotel manager' => ['hotelManager', true],
            'reception' => ['reception', true],
            'guest' => ['guest', false],
        ];
    }

    #[DataProvider('roleMatrix')]
    public function test_checkout_permissions(string $factory, bool $allowed): void
    {
        $hotel = Hotel::factory()->create();
        $user = User::factory()->{$factory}()->create();
        if ($factory !== 'guest') {
            $user->hotels()->attach($hotel);
        }
        $reservation = $this->reservation($hotel);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/checkout")
            ->assertStatus($allowed ? 200 : ($factory === 'guest' ? 404 : 403));
    }

    #[DataProvider('roleMatrix')]
    public function test_invoice_view_permissions(string $factory, bool $allowed): void
    {
        $hotel = Hotel::factory()->create();
        $user = User::factory()->{$factory}()->create();
        if ($factory !== 'guest') {
            $user->hotels()->attach($hotel);
        }
        $reservation = $this->reservation($hotel);

        // Complete a checkout first (as an owner) so an invoice exists.
        $this->actingAs(User::factory()->groupOwner()->create(), 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/checkout")->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/invoice")
            ->assertStatus($allowed ? 200 : ($factory === 'guest' ? 404 : 403));
    }

    public function test_client_supplied_hotel_id_cannot_widen_scope(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);
        $reservation = $this->reservation($hotelB);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/checkout", ['hotel_id' => $hotelA->id])
            ->assertStatus(404);
    }
}
