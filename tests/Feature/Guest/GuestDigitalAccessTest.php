<?php

namespace Tests\Feature\Guest;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class GuestDigitalAccessTest extends TestCase
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

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/access")->assertStatus(401);
    }

    public function test_guest_reads_their_own_access_status(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);
        AccessGrant::factory()->active()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id,
        ]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/access")
            ->assertOk()
            ->assertJsonPath('data.status', AccessGrant::STATUS_ACTIVE)
            ->assertJsonPath('data.credential', fn ($v) => $v !== null);
    }

    public function test_guest_cannot_reach_another_guests_access_grant(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$other->id}/access")->assertStatus(404);
    }

    public function test_there_is_no_guest_revoke_route(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/access/revoke")->assertStatus(404);
    }
}
