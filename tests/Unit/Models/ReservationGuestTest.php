<?php

namespace Tests\Unit\Models;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Models\ReservationGuest;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class ReservationGuestTest extends TestCase
{
    public function test_factory_creates_a_valid_reservation_guest(): void
    {
        $reservationGuest = ReservationGuest::factory()->create();

        $this->assertDatabaseHas('reservation_guests', ['id' => $reservationGuest->id]);
        $this->assertFalse($reservationGuest->is_primary);
    }

    public function test_primary_state_sets_is_primary_true(): void
    {
        $reservationGuest = ReservationGuest::factory()->primary()->create();

        $this->assertTrue($reservationGuest->fresh()->is_primary);
    }

    public function test_belongs_to_reservation_and_guest(): void
    {
        $reservation = Reservation::factory()->create();
        $guest = Guest::factory()->create();
        $reservationGuest = ReservationGuest::factory()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
        ]);

        $this->assertTrue($reservationGuest->reservation->is($reservation));
        $this->assertTrue($reservationGuest->guest->is($guest));
    }

    public function test_reservation_has_many_reservation_guests(): void
    {
        $reservation = Reservation::factory()->create();
        ReservationGuest::factory()->count(3)->create(['reservation_id' => $reservation->id]);

        $this->assertCount(3, $reservation->reservationGuests);
    }

    public function test_duplicate_guest_assignment_within_the_same_reservation_is_rejected(): void
    {
        $reservation = Reservation::factory()->create();
        $guest = Guest::factory()->create();
        ReservationGuest::factory()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        $this->expectException(QueryException::class);

        ReservationGuest::factory()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
    }

    public function test_the_same_guest_can_be_assigned_to_a_different_reservation(): void
    {
        $guest = Guest::factory()->create();
        ReservationGuest::factory()->create(['guest_id' => $guest->id]);
        $secondAssignment = ReservationGuest::factory()->create(['guest_id' => $guest->id]);

        $this->assertDatabaseHas('reservation_guests', ['id' => $secondAssignment->id, 'guest_id' => $guest->id]);
    }

    public function test_deleting_a_reservation_cascades_to_its_reservation_guests(): void
    {
        $reservation = Reservation::factory()->create();
        $reservationGuest = ReservationGuest::factory()->create(['reservation_id' => $reservation->id]);

        $reservation->delete();

        $this->assertDatabaseMissing('reservation_guests', ['id' => $reservationGuest->id]);
    }

    public function test_deleting_a_guest_with_a_reservation_guest_assignment_is_blocked(): void
    {
        $reservationGuest = ReservationGuest::factory()->create();

        $this->expectException(QueryException::class);

        $reservationGuest->guest->delete();
    }
}
