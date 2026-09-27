<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class GuestLoyaltyTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function reservationFor(Guest $guest, string $status = Reservation::STATUS_INVOICED): Reservation
    {
        $group = HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        LoyaltyRule::factory()->active()->create(['hotel_group_id' => $group->id]);

        return Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => $status, 'price_snapshot' => '200.00',
        ]);
    }

    public function test_unauthenticated_is_401(): void
    {
        $reservation = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/loyalty")->assertStatus(401);
    }

    public function test_guest_reads_their_own_account_and_ledger(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);
        LoyaltyAccount::factory()->create(['guest_id' => $guest->id, 'points_balance' => 500]);
        LoyaltyTransaction::factory()->create([
            'loyalty_account_id' => LoyaltyAccount::query()->where('guest_id', $guest->id)->first()->id,
            'type' => LoyaltyTransaction::TYPE_EARN, 'points' => 200,
        ]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/loyalty")
            ->assertOk()
            ->assertJsonPath('data.guest_id', $guest->id);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/loyalty/transactions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_guest_redeems_points_against_their_own_reservation(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest, Reservation::STATUS_CHECKED_IN);
        LoyaltyAccount::factory()->create(['guest_id' => $guest->id, 'points_balance' => 500]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/loyalty/redeem", ['points' => 100])
            ->assertStatus(201)
            ->assertJsonPath('data.type', LoyaltyTransaction::TYPE_REDEEM)
            ->assertJsonPath('data.points', -100);
    }

    public function test_a_client_supplied_guest_id_or_balance_is_never_honoured(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest, Reservation::STATUS_CHECKED_IN);
        LoyaltyAccount::factory()->create(['guest_id' => $guest->id, 'points_balance' => 10]);

        // Redeeming more than the balance is refused server-side regardless
        // of what the client believes its balance to be.
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/loyalty/redeem", ['points' => 5000])
            ->assertStatus(422);
    }

    public function test_guest_cannot_reach_another_guests_loyalty_account(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$other->id}/loyalty")->assertStatus(404);
        $this->postJson("/api/v1/guest/reservations/{$other->id}/loyalty/redeem", ['points' => 10])->assertStatus(404);
    }

    public function test_there_is_no_guest_earn_route(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/loyalty/earn")->assertStatus(404);
    }
}
