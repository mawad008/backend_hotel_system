<?php

namespace Tests\Feature\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use Tests\TestCase;

class FolioApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function reservation(?Hotel $hotel = null): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => Reservation::STATUS_IN_STAY,
        ]);
    }

    public function test_folio_shape_and_totals(): void
    {
        $reservation = $this->reservation();
        FolioCharge::factory()->amount('10.00', 2)->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id, 'source_id' => 1,
        ]);
        FolioCharge::factory()->amount('5.00', 1)->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id, 'source_id' => 2,
        ]);
        $payment = Payment::factory()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id,
            'status' => Payment::STATUS_CAPTURED, 'amount' => '15.00', 'currency' => 'USD',
        ]);
        // payments_total is the succeeded capture/settlement transaction
        // history (Phase 9 review fix), not payments.amount.
        PaymentTransaction::factory()->create([
            'payment_id' => $payment->id, 'type' => PaymentTransaction::TYPE_CAPTURE,
            'status' => PaymentTransaction::STATUS_SUCCEEDED, 'amount' => '15.00',
        ]);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/folio")
            ->assertOk()
            ->assertJsonPath('data.reservation.id', $reservation->id)
            ->assertJsonPath('data.totals.charges_total', '25.00')
            ->assertJsonPath('data.totals.payments_total', '15.00')
            ->assertJsonPath('data.totals.outstanding_total', '10.00')
            ->assertJsonPath('data.payment_summary.is_captured', true)
            ->assertJsonCount(2, 'data.charges')
            ->assertJsonStructure(['data' => ['reservation', 'currency', 'charges', 'totals', 'payment_summary']]);
    }

    public function test_hold_only_payment_is_not_counted_as_money_in(): void
    {
        $reservation = $this->reservation();
        FolioCharge::factory()->amount('40.00', 1)->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id, 'source_id' => 1,
        ]);
        Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id, 'amount' => '200.00',
        ]);

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/folio")
            ->assertOk()
            ->assertJsonPath('data.totals.payments_total', '0.00')
            ->assertJsonPath('data.totals.outstanding_total', '40.00')
            ->assertJsonPath('data.payment_summary.is_captured', false);
    }

    public function test_folio_never_exposes_payment_secrets(): void
    {
        $reservation = $this->reservation();
        Payment::factory()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id,
            'status' => Payment::STATUS_CAPTURED, 'amount' => '15.00',
            'provider_customer_ref' => 'cus_SECRET_REF_123',
        ]);

        $body = $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/folio")->getContent();

        foreach (['provider_customer_ref', 'cus_SECRET_REF_123', 'card', 'cvv'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_empty_folio(): void
    {
        $reservation = $this->reservation();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/folio")
            ->assertOk()
            ->assertJsonPath('data.totals.charges_total', '0.00')
            ->assertJsonPath('data.totals.outstanding_total', '0.00')
            ->assertJsonPath('data.payment_summary', null);
    }

    public function test_cross_hotel_folio_is_a_404(): void
    {
        $assigned = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);
        $reservation = $this->reservation(Hotel::factory()->create());

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/folio")
            ->assertStatus(404);
    }
}
