<?php

namespace Tests\Feature\DigitalAccess;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationService;
use Tests\TestCase;

/**
 * The room credential follows the stay: it stops working when the stay ends
 * (checked_out / invoiced / cancelled) and keeps working through an Extend
 * Stay until the new check-out day ends.
 */
class AccessLifecycleTest extends TestCase
{
    private function inStayWithActiveKey(Hotel $hotel): array
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => '300.00']);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        $reservation = Reservation::factory()->withRoom()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_IN_STAY,
            'check_in' => now()->subDay()->toDateString(),
            'check_out' => now()->addDay()->toDateString(),
            'price_snapshot' => '600.00',
        ]);
        $grant = AccessGrant::factory()->active()->create([
            'reservation_id' => $reservation->id,
            'expires_at' => now()->addDay()->endOfDay(),
        ]);

        return [$reservation, $grant];
    }

    public function test_the_key_is_revoked_when_the_stay_is_checked_out(): void
    {
        [$reservation, $grant] = $this->inStayWithActiveKey(Hotel::factory()->create());

        app(ReservationService::class)->transitionTo($reservation, Reservation::STATUS_CHECKOUT_IN_PROGRESS, null);
        $this->assertSame(AccessGrant::STATUS_ACTIVE, $grant->fresh()->status);

        app(ReservationService::class)->transitionTo($reservation->fresh(), Reservation::STATUS_CHECKED_OUT, null);

        $grant->refresh();
        $this->assertSame(AccessGrant::STATUS_REVOKED, $grant->status);
        $this->assertNull($grant->credential);
    }

    public function test_a_reservation_without_a_key_still_checks_out(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_PENDING,
        ]);

        $cancelled = app(ReservationService::class)->transitionTo($reservation, Reservation::STATUS_CANCELLED, null);

        $this->assertSame(Reservation::STATUS_CANCELLED, $cancelled->status);
    }

    public function test_extending_the_stay_extends_the_key_to_the_new_check_out_day(): void
    {
        $hotel = Hotel::factory()->create();
        [$reservation, $grant] = $this->inStayWithActiveKey($hotel);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);
        $newCheckOut = now()->addDays(3);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/extend", [
                'new_check_out' => $newCheckOut->toDateString(),
            ])
            ->assertOk();

        $this->assertTrue($grant->fresh()->expires_at->equalTo($newCheckOut->copy()->endOfDay()->startOfSecond()));
        $this->assertSame(AccessGrant::STATUS_ACTIVE, $grant->fresh()->status);
    }
}
