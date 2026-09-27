<?php

namespace Tests\Feature\Guest;

use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class GuestPaymentTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    public function test_payment_view_returns_null_when_no_deposit_has_started(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/payment")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_payment_view_returns_the_reservations_payment_state(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);
        $payment = Payment::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'status' => Payment::STATUS_HOLD_ACTIVE,
            'amount' => '300.00',
        ]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.status', Payment::STATUS_HOLD_ACTIVE)
            ->assertJsonPath('data.amount', '300.00')
            // no hotel scope concept for a guest, no provider internals
            ->assertJsonMissingPath('data.hotel_id')
            ->assertJsonMissingPath('data.metadata');
    }

    public function test_payment_view_is_ownership_scoped(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$other->id}/payment")->assertStatus(404);
    }

    public function test_deposit_hold_is_the_hotels_percentage_of_the_room_price(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create([
            'guest_id' => $guest->id,
            'status' => Reservation::STATUS_PENDING,
            'price_snapshot' => '100.00',
        ]);
        $reservation->hotel->update(['deposit_percentage' => 10]);

        // 100 SAR room at the hotel's 10% → a 10 SAR deposit.
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/payment/hold")
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Payment::STATUS_HOLD_ACTIVE)
            ->assertJsonPath('data.amount', '10.00');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_each_hotel_applies_its_own_deposit_percentage(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create([
            'guest_id' => $guest->id,
            'status' => Reservation::STATUS_PENDING,
            'price_snapshot' => '1250.00',
        ]);
        $reservation->hotel->update(['deposit_percentage' => 12.5]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/payment/hold")
            ->assertStatus(201)
            ->assertJsonPath('data.amount', '156.25');
    }

    public function test_deposit_hold_is_blocked_when_the_hotel_has_no_percentage(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create([
            'guest_id' => $guest->id,
            'status' => Reservation::STATUS_PENDING,
        ]);
        $reservation->hotel->update(['deposit_percentage' => null]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/payment/hold")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.reason', 'deposit_amount_rule_undefined');

        // Nothing was created.
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_deposit_hold_is_ownership_scoped(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create();

        $this->postJson("/api/v1/guest/reservations/{$other->id}/payment/hold")->assertStatus(404);
    }
}
