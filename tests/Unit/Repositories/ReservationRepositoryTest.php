<?php

namespace Tests\Unit\Repositories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\EloquentReservationRepository;
use Tests\TestCase;

class ReservationRepositoryTest extends TestCase
{
    private EloquentReservationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentReservationRepository;
    }

    public function test_paginate_accessible_by_returns_every_reservation_for_a_group_owner(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        Reservation::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelA->id])->id]);
        Reservation::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id]);
        $owner = User::factory()->groupOwner()->create();

        $result = $this->repository->paginateAccessibleBy($owner);

        $this->assertSame(2, $result->total());
    }

    public function test_paginate_accessible_by_excludes_unassigned_hotels_for_a_manager(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        Reservation::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelA->id])->id]);
        Reservation::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $result = $this->repository->paginateAccessibleBy($manager);

        $this->assertSame(1, $result->total());
    }

    public function test_paginate_accessible_by_returns_nothing_for_a_user_with_no_assignments(): void
    {
        $hotel = Hotel::factory()->create();
        Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotel->id])->id]);
        $reception = User::factory()->reception()->create();

        $result = $this->repository->paginateAccessibleBy($reception);

        $this->assertSame(0, $result->total());
    }

    public function test_find_accessible_by_returns_the_reservation_for_an_assigned_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotel->id])->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $found = $this->repository->findAccessibleBy($manager, $reservation->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($reservation));
    }

    public function test_find_accessible_by_returns_null_for_an_unassigned_hotels_reservation(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $reservation = Reservation::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $this->assertNull($this->repository->findAccessibleBy($manager, $reservation->id));
    }

    public function test_find_accessible_by_returns_null_for_a_missing_reservation(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->assertNull($this->repository->findAccessibleBy($owner, 999999));
    }

    public function test_create_persists_a_reservation(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $guest = Guest::factory()->create();

        $reservation = $this->repository->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'room_id' => null,
            'guest_id' => $guest->id,
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
            'status' => Reservation::STATUS_PENDING,
            'price_snapshot' => $roomType->base_price,
        ]);

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'hotel_id' => $hotel->id]);
    }

    public function test_count_overlapping_for_room_counts_a_blocking_overlapping_reservation(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_id' => $room->id,
            'check_in' => '2026-10-10', 'check_out' => '2026-10-15',
        ]);

        $count = $this->repository->countOverlappingForRoom($room->id, '2026-10-12', '2026-10-18');

        $this->assertSame(1, $count);
    }

    public function test_count_overlapping_for_room_excludes_non_overlapping_ranges(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_id' => $room->id,
            'check_in' => '2026-10-10', 'check_out' => '2026-10-12',
        ]);

        // Adjacent, not overlapping.
        $count = $this->repository->countOverlappingForRoom($room->id, '2026-10-12', '2026-10-15');

        $this->assertSame(0, $count);
    }

    public function test_count_overlapping_for_room_excludes_cancelled_reservations(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        Reservation::factory()->cancelled()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_id' => $room->id,
            'check_in' => '2026-10-10', 'check_out' => '2026-10-15',
        ]);

        $count = $this->repository->countOverlappingForRoom($room->id, '2026-10-12', '2026-10-18');

        $this->assertSame(0, $count);
    }

    public function test_count_overlapping_for_room_type_counts_assigned_and_unassigned_together(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_id' => $room->id,
            'check_in' => '2026-10-10', 'check_out' => '2026-10-15',
        ]);
        Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_id' => null,
            'check_in' => '2026-10-11', 'check_out' => '2026-10-13',
        ]);

        $count = $this->repository->countOverlappingForRoomType($roomType->id, '2026-10-10', '2026-10-15');

        $this->assertSame(2, $count);
    }
}
