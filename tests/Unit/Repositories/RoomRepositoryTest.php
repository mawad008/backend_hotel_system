<?php

namespace Tests\Unit\Repositories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\EloquentRoomRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoomRepositoryTest extends TestCase
{
    private EloquentRoomRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentRoomRepository;
    }

    public function test_paginate_accessible_by_returns_rooms_for_the_given_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->count(3)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $result = $this->repository->paginateAccessibleBy($owner, $hotel);

        $this->assertSame(3, $result->total());
    }

    public function test_paginate_accessible_by_excludes_other_hotels(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotelA->id]);
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotelB->id]);
        Room::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => $roomTypeA->id]);
        Room::factory()->count(2)->create(['hotel_id' => $hotelB->id, 'room_type_id' => $roomTypeB->id]);
        $owner = User::factory()->groupOwner()->create();

        $result = $this->repository->paginateAccessibleBy($owner, $hotelA);

        $this->assertSame(1, $result->total());
    }

    public function test_paginate_accessible_by_returns_nothing_for_an_unassigned_hotel_manager(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $manager = User::factory()->hotelManager()->create();

        $result = $this->repository->paginateAccessibleBy($manager, $hotel);

        $this->assertSame(0, $result->total());
    }

    public function test_room_type_id_filter_narrows_the_list(): void
    {
        $hotel = Hotel::factory()->create();
        // Explicit distinct names to avoid an intermittent
        // UNIQUE(hotel_id, name) collision — see RoomTypeRepositoryTest.
        $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Test Room Type A']);
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Test Room Type B']);
        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomTypeA->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomTypeB->id]);
        $owner = User::factory()->groupOwner()->create();

        $result = $this->repository->paginateAccessibleBy($owner, $hotel, $roomTypeA->id);

        $this->assertSame(2, $result->total());
    }

    public function test_find_for_update_locks_the_row_within_a_transaction(): void
    {
        $room = Room::factory()->create();

        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });

        DB::transaction(function () use ($room) {
            $this->repository->findForUpdate($room->id);
        });

        $this->assertTrue(
            collect($sql)->contains(fn ($statement) => str_contains(strtolower($statement), 'for update')),
            'Expected findForUpdate() to issue a SELECT ... FOR UPDATE query.'
        );
    }

    public function test_find_returns_null_for_a_missing_room(): void
    {
        $this->assertNull($this->repository->find(999999));
    }
}
