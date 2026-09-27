<?php

namespace Tests\Feature\Guest;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use Tests\TestCase;

class GuestFolioTest extends TestCase
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

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/folio")->assertStatus(401);
    }

    public function test_guest_reads_their_own_folio(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id, 'price_snapshot' => '150.00']);
        FolioCharge::factory()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id,
            'source_type' => FolioCharge::SOURCE_ACCOMMODATION, 'source_id' => $reservation->id,
            'total_amount' => '150.00', 'unit_amount' => '150.00', 'quantity' => 1,
            'status' => FolioCharge::STATUS_POSTED,
        ]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/folio")
            ->assertOk()
            ->assertJsonPath('data.totals.charges_total', '150.00')
            ->assertJsonPath('data.reservation.id', $reservation->id)
            ->assertJsonMissingPath('data.payment_summary.provider');
    }

    public function test_guest_cannot_reach_another_guests_folio(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$other->id}/folio")->assertStatus(404);
    }
}
