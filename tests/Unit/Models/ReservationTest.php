<?php

namespace Tests\Unit\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    public function test_factory_creates_a_valid_reservation(): void
    {
        $reservation = Reservation::factory()->create();

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->status);
    }

    public function test_factory_derived_hotel_id_always_matches_its_room_types_hotel(): void
    {
        $reservation = Reservation::factory()->create();

        $this->assertSame($reservation->roomType->hotel_id, $reservation->hotel_id);
    }

    public function test_hotel_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        Reservation::factory()->create(['hotel_id' => null]);
    }

    public function test_room_type_id_is_required(): void
    {
        // hotel_id is overridden alongside room_type_id so the factory's
        // derived-hotel_id closure (which needs a real room_type_id) never
        // runs — isolating this test to the NOT NULL constraint itself.
        $hotel = Hotel::factory()->create();

        $this->expectException(QueryException::class);

        Reservation::factory()->create(['room_type_id' => null, 'hotel_id' => $hotel->id]);
    }

    public function test_guest_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        Reservation::factory()->create(['guest_id' => null]);
    }

    public function test_room_id_is_nullable(): void
    {
        $reservation = Reservation::factory()->create();

        $this->assertNull($reservation->fresh()->room_id);
    }

    public function test_room_id_can_be_assigned_via_the_with_room_state(): void
    {
        $reservation = Reservation::factory()->withRoom()->create();

        $this->assertNotNull($reservation->fresh()->room_id);
        $this->assertSame($reservation->hotel_id, $reservation->room->hotel_id);
        $this->assertSame($reservation->room_type_id, $reservation->room->room_type_id);
    }

    public function test_created_by_staff_id_is_nullable(): void
    {
        $reservation = Reservation::factory()->create();

        $this->assertNull($reservation->fresh()->created_by_staff_id);
    }

    public function test_created_by_staff_id_can_be_set_to_a_user(): void
    {
        $staff = User::factory()->hotelManager()->create();
        $reservation = Reservation::factory()->create(['created_by_staff_id' => $staff->id]);

        $this->assertSame($staff->id, $reservation->fresh()->created_by_staff_id);
    }

    public function test_cancelled_at_and_cancellation_reason_are_nullable(): void
    {
        $reservation = Reservation::factory()->create();

        $this->assertNull($reservation->fresh()->cancelled_at);
        $this->assertNull($reservation->fresh()->cancellation_reason);
    }

    public function test_cancelled_state_populates_cancellation_fields(): void
    {
        $reservation = Reservation::factory()->cancelled()->create();

        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->status);
        $this->assertNotNull($reservation->fresh()->cancelled_at);
        $this->assertNotNull($reservation->fresh()->cancellation_reason);
    }

    public function test_status_persists_and_only_accepts_approved_state_machine_values(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_IN_STAY]);

        $this->assertSame('in_stay', $reservation->fresh()->status);

        $this->expectException(QueryException::class);

        DB::table('reservations')->where('id', $reservation->id)->update(['status' => 'not_a_real_status']);
    }

    public function test_all_ten_approved_statuses_are_accepted_by_the_column(): void
    {
        $statuses = [
            Reservation::STATUS_PENDING,
            Reservation::STATUS_DEPOSIT_HELD,
            Reservation::STATUS_VERIFIED,
            Reservation::STATUS_CHECKED_IN,
            Reservation::STATUS_IN_STAY,
            Reservation::STATUS_CHECKOUT_IN_PROGRESS,
            Reservation::STATUS_CHECKOUT_BLOCKED,
            Reservation::STATUS_CHECKED_OUT,
            Reservation::STATUS_INVOICED,
            Reservation::STATUS_CANCELLED,
        ];

        foreach ($statuses as $status) {
            $reservation = Reservation::factory()->create(['status' => $status]);
            $this->assertSame($status, $reservation->fresh()->status);
        }
    }

    public function test_check_in_and_check_out_dates_persist(): void
    {
        $reservation = Reservation::factory()->create([
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
        ]);

        $fresh = $reservation->fresh();
        $this->assertSame('2026-10-01', $fresh->check_in->format('Y-m-d'));
        $this->assertSame('2026-10-05', $fresh->check_out->format('Y-m-d'));
    }

    public function test_price_snapshot_persists_as_a_decimal(): void
    {
        $reservation = Reservation::factory()->create(['price_snapshot' => 199.99]);

        $this->assertSame('199.99', $reservation->fresh()->price_snapshot);
    }

    public function test_belongs_to_hotel_room_type_and_guest(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'guest_id' => $guest->id,
        ]);

        $this->assertTrue($reservation->hotel->is($hotel));
        $this->assertTrue($reservation->roomType->is($roomType));
        $this->assertTrue($reservation->guest->is($guest));
    }

    public function test_deleting_a_room_referenced_by_a_reservation_is_blocked(): void
    {
        $reservation = Reservation::factory()->withRoom()->create();

        $this->expectException(QueryException::class);

        $reservation->room->delete();
    }

    public function test_deleting_a_room_type_with_reservations_is_blocked(): void
    {
        $reservation = Reservation::factory()->create();

        $this->expectException(QueryException::class);

        $reservation->roomType->delete();
    }

    public function test_deleting_a_guest_with_reservations_is_blocked(): void
    {
        $reservation = Reservation::factory()->create();

        $this->expectException(QueryException::class);

        $reservation->guest->delete();
    }
}
