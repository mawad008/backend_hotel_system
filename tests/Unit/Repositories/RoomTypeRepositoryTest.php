<?php

namespace Tests\Unit\Repositories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use Tests\TestCase;

class RoomTypeRepositoryTest extends TestCase
{
    private EloquentRoomTypeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentRoomTypeRepository;
    }

    public function test_paginate_accessible_by_returns_every_room_type_for_a_group_owner(): void
    {
        $hotel = Hotel::factory()->create();
        // Explicit distinct names: RoomTypeFactory's default name has a
        // small, fixed set of combinations and does not guarantee
        // uniqueness, which would otherwise risk an intermittent
        // UNIQUE(hotel_id, name) collision when creating more than one
        // Room Type for the same hotel.
        RoomType::factory()->sequence(
            ['name' => 'Test Room Type A'],
            ['name' => 'Test Room Type B'],
            ['name' => 'Test Room Type C'],
        )->count(3)->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $result = $this->repository->paginateAccessibleBy($owner, $hotel);

        $this->assertSame(3, $result->total());
    }

    public function test_paginate_accessible_by_excludes_other_hotels_even_for_a_group_owner(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        RoomType::factory()->create(['hotel_id' => $hotelA->id]);
        RoomType::factory()->sequence(
            ['name' => 'Test Room Type A'],
            ['name' => 'Test Room Type B'],
        )->count(2)->create(['hotel_id' => $hotelB->id]);
        $owner = User::factory()->groupOwner()->create();

        $result = $this->repository->paginateAccessibleBy($owner, $hotelA);

        $this->assertSame(1, $result->total());
    }

    public function test_paginate_accessible_by_returns_nothing_for_an_unassigned_hotel_manager(): void
    {
        $hotel = Hotel::factory()->create();
        RoomType::factory()->sequence(
            ['name' => 'Test Room Type A'],
            ['name' => 'Test Room Type B'],
        )->count(2)->create(['hotel_id' => $hotel->id]);
        $manager = User::factory()->hotelManager()->create();

        $result = $this->repository->paginateAccessibleBy($manager, $hotel);

        $this->assertSame(0, $result->total());
    }

    public function test_paginate_accessible_by_returns_rows_for_an_assigned_hotel_manager(): void
    {
        $hotel = Hotel::factory()->create();
        // Explicit distinct names: RoomTypeFactory's default name has a
        // small, fixed set of combinations and does not guarantee
        // uniqueness, which would otherwise risk an intermittent
        // UNIQUE(hotel_id, name) collision when creating more than one
        // Room Type for the same hotel.
        RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Test Room Type A']);
        RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Test Room Type B']);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $result = $this->repository->paginateAccessibleBy($manager, $hotel);

        $this->assertSame(2, $result->total());
    }

    public function test_inventory_snapshot_counts_reflect_actual_room_statuses(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        Room::factory()->underMaintenance()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        $found = $this->repository->find($roomType->id);

        $this->assertSame(3, $found->rooms_count);
        $this->assertSame(2, $found->available_rooms_count);
        $this->assertSame(1, $found->maintenance_rooms_count);
    }

    public function test_inventory_snapshot_counts_are_zero_for_a_room_type_with_no_rooms(): void
    {
        $roomType = RoomType::factory()->create();

        $found = $this->repository->find($roomType->id);

        $this->assertSame(0, $found->rooms_count);
        $this->assertSame(0, $found->available_rooms_count);
        $this->assertSame(0, $found->maintenance_rooms_count);
    }

    public function test_find_returns_null_for_a_missing_room_type(): void
    {
        $this->assertNull($this->repository->find(999999));
    }
}
