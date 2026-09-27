<?php

namespace Tests\Unit\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoomTest extends TestCase
{
    public function test_factory_creates_a_valid_room_with_default_available_status(): void
    {
        $room = Room::factory()->create();

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertSame('available', $room->status);
    }

    public function test_factory_derived_hotel_id_always_matches_its_room_types_hotel(): void
    {
        $room = Room::factory()->create();

        $this->assertSame($room->roomType->hotel_id, $room->hotel_id);
    }

    public function test_belongs_to_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        $this->assertTrue($room->hotel->is($hotel));
    }

    public function test_belongs_to_room_type(): void
    {
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->create(['room_type_id' => $roomType->id, 'hotel_id' => $roomType->hotel_id]);

        $this->assertTrue($room->roomType->is($roomType));
    }

    public function test_under_maintenance_factory_state(): void
    {
        $room = Room::factory()->underMaintenance()->create();

        $this->assertSame('under_maintenance', $room->status);
    }

    public function test_booked_factory_state_is_available_but_not_the_default(): void
    {
        $default = Room::factory()->create();
        $this->assertNotSame('booked', $default->status);

        $booked = Room::factory()->booked()->create();
        $this->assertSame('booked', $booked->status);
    }

    public function test_status_column_only_accepts_the_three_approved_values(): void
    {
        $room = Room::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('rooms')->where('id', $room->id)->update(['status' => 'checked_in']);
    }

    public function test_unique_hotel_id_and_room_number_constraint_is_enforced(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_number' => '101']);

        $this->expectException(QueryException::class);

        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_number' => '101']);
    }

    public function test_the_same_room_number_is_allowed_across_different_hotels(): void
    {
        $roomTypeA = RoomType::factory()->create();
        $roomTypeB = RoomType::factory()->create();

        Room::factory()->create(['hotel_id' => $roomTypeA->hotel_id, 'room_type_id' => $roomTypeA->id, 'room_number' => '101']);
        $room = Room::factory()->create(['hotel_id' => $roomTypeB->hotel_id, 'room_type_id' => $roomTypeB->id, 'room_number' => '101']);

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'room_number' => '101']);
    }

    public function test_accessible_by_returns_every_row_for_a_group_owner(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->count(3)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->assertCount(3, Room::query()->accessibleBy($owner)->get());
    }

    public function test_accessible_by_filters_to_assigned_hotels_for_non_owners(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotelA->id]);
        $roomTypeB = RoomType::factory()->create(['hotel_id' => $hotelB->id]);

        $roomA = Room::factory()->create(['hotel_id' => $hotelA->id, 'room_type_id' => $roomTypeA->id]);
        Room::factory()->create(['hotel_id' => $hotelB->id, 'room_type_id' => $roomTypeB->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $result = Room::query()->accessibleBy($manager)->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($roomA));
    }

    public function test_accessible_by_returns_nothing_for_a_user_with_no_assignments(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

        $reception = User::factory()->reception()->create();

        $this->assertCount(0, Room::query()->accessibleBy($reception)->get());
    }
}
