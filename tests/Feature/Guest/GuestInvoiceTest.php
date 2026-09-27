<?php

namespace Tests\Feature\Guest;

use App\Domain\Checkout\Models\Invoice;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class GuestInvoiceTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    public function test_unauthenticated_is_401(): void
    {
        $reservation = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/invoice")->assertStatus(401);
    }

    public function test_guest_reads_their_own_invoice(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);
        $invoice = Invoice::factory()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id,
        ]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/invoice")
            ->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonMissingPath('data.created_by_user_id');
    }

    public function test_missing_invoice_is_404(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/invoice")->assertStatus(404);
    }

    public function test_guest_cannot_reach_another_guests_invoice(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create();
        Invoice::factory()->create(['reservation_id' => $other->id, 'hotel_id' => $other->hotel_id]);

        $this->getJson("/api/v1/guest/reservations/{$other->id}/invoice")->assertStatus(404);
    }
}
