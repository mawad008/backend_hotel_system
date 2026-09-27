<?php

namespace Tests\Feature\DigitalAccess;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

/** Approved 2026-09-26: per-hotel check-in mode; check-in starts the stay. */
class CheckInModeTest extends TestCase
{
    private function ready(string $mode, bool $withRoom = true): array
    {
        $hotel = Hotel::factory()->create(['check_in_mode' => $mode]);
        $guest = Guest::factory()->create();
        $factory = Reservation::factory()->state([
            'guest_id' => $guest->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotel->id])->id,
            'status' => Reservation::STATUS_VERIFIED,
            'check_out' => now()->addDays(3)->format('Y-m-d'),
        ]);
        $reservation = ($withRoom ? $factory->withRoom() : $factory)->create();
        Payment::factory()->holdActive()->create(['reservation_id' => $reservation->id, 'hotel_id' => $hotel->id]);
        IdentityVerificationSession::factory()->autoApproved()->create(['reservation_id' => $reservation->id]);

        return [$hotel, $guest, $reservation];
    }

    private function asGuest(Guest $guest): void
    {
        $this->withToken($guest->createToken('guest-api')->plainTextToken);
    }

    public function test_self_check_in_starts_the_stay_when_the_hotel_allows_it(): void
    {
        [, $guest, $reservation] = $this->ready(Hotel::CHECK_IN_SELF);
        $this->asGuest($guest);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}")->assertJsonPath('data.check_in_availability.allowed', true);
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")->assertCreated();

        $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);
    }

    public function test_reception_only_hotels_refuse_self_check_in(): void
    {
        [, $guest, $reservation] = $this->ready(Hotel::CHECK_IN_RECEPTION);
        $this->asGuest($guest);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}")
            ->assertJsonPath('data.check_in_availability.allowed', false)
            ->assertJsonPath('data.check_in_availability.reason', 'check_in_channel_not_allowed');
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")->assertStatus(422);
        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
    }

    public function test_self_only_hotels_refuse_reception_check_in_and_both_allows_it(): void
    {
        [, , $selfOnly] = $this->ready(Hotel::CHECK_IN_SELF);
        $owner = User::factory()->groupOwner()->create();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/check-in/{$selfOnly->id}")->assertStatus(422);

        [, , $both] = $this->ready(Hotel::CHECK_IN_BOTH);
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/check-in/{$both->id}")->assertCreated();
        $this->assertSame(Reservation::STATUS_IN_STAY, $both->fresh()->status);
    }

    public function test_check_in_waits_for_a_room_assignment(): void
    {
        [, $guest, $reservation] = $this->ready(Hotel::CHECK_IN_BOTH, withRoom: false);
        $this->asGuest($guest);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}")->assertJsonPath('data.check_in_availability.reason', 'room_not_assigned');
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")->assertStatus(422);
    }

    public function test_payment_confirms_but_never_starts_the_stay(): void
    {
        [, $guest, $reservation] = $this->ready(Hotel::CHECK_IN_BOTH);
        $reservation->update(['status' => Reservation::STATUS_DEPOSIT_HELD]);

        $this->assertNotSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);
        $this->asGuest($guest);
        $this->getJson("/api/v1/guest/reservations/{$reservation->id}")->assertJsonPath('data.check_in_availability.allowed', false);
    }
}
