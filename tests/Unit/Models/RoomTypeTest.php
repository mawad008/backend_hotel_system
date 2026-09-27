<?php

namespace Tests\Unit\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class RoomTypeTest extends TestCase
{
    public function test_factory_creates_a_valid_room_type(): void
    {
        $roomType = RoomType::factory()->create();

        $this->assertDatabaseHas('room_types', ['id' => $roomType->id]);
        $this->assertNotNull($roomType->hotel_id);
        $this->assertGreaterThanOrEqual(1, $roomType->capacity);
        $this->assertTrue($roomType->is_active);
    }

    public function test_amenities_is_cast_to_an_array(): void
    {
        $roomType = RoomType::factory()->create(['amenities' => ['wifi', 'tv']]);

        $this->assertIsArray($roomType->fresh()->amenities);
        $this->assertSame(['wifi', 'tv'], $roomType->fresh()->amenities);
    }

    public function test_amenities_can_be_null(): void
    {
        $roomType = RoomType::factory()->create(['amenities' => null]);

        $this->assertNull($roomType->fresh()->amenities);
    }

    public function test_belongs_to_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        $this->assertTrue($roomType->hotel->is($hotel));
    }

    public function test_has_many_rooms(): void
    {
        $roomType = RoomType::factory()->create();
        Room::factory()->count(2)->create(['room_type_id' => $roomType->id, 'hotel_id' => $roomType->hotel_id]);

        $this->assertCount(2, $roomType->rooms);
    }

    public function test_unique_hotel_id_and_name_constraint_is_enforced(): void
    {
        $hotel = Hotel::factory()->create();
        RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Deluxe Double']);

        $this->expectException(QueryException::class);

        RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Deluxe Double']);
    }

    public function test_the_same_name_is_allowed_across_different_hotels(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();

        RoomType::factory()->create(['hotel_id' => $hotelA->id, 'name' => 'Deluxe Double']);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotelB->id, 'name' => 'Deluxe Double']);

        $this->assertDatabaseHas('room_types', ['id' => $roomType->id, 'name' => 'Deluxe Double']);
    }

    public function test_factory_generates_unique_names_for_many_room_types_under_the_same_hotel(): void
    {
        $hotel = Hotel::factory()->create();

        $roomTypes = RoomType::factory()->count(50)->create(['hotel_id' => $hotel->id]);

        $this->assertCount(50, $roomTypes);
        $this->assertSame(50, $roomTypes->pluck('name')->unique()->count());
    }

    public function test_capacity_must_be_at_least_one_at_the_database_level(): void
    {
        $this->expectException(QueryException::class);

        RoomType::factory()->create(['capacity' => 0]);
    }

    public function test_accessible_by_returns_every_row_for_a_group_owner(): void
    {
        $hotel = Hotel::factory()->create();
        RoomType::factory()->count(3)->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->assertCount(3, RoomType::query()->accessibleBy($owner)->get());
    }

    public function test_accessible_by_filters_to_assigned_hotels_for_non_owners(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotelA->id]);
        RoomType::factory()->create(['hotel_id' => $hotelB->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $result = RoomType::query()->accessibleBy($manager)->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($roomTypeA));
    }

    public function test_accessible_by_returns_nothing_for_a_user_with_no_assignments(): void
    {
        $hotel = Hotel::factory()->create();
        RoomType::factory()->count(2)->create(['hotel_id' => $hotel->id]);

        $reception = User::factory()->reception()->create();

        $this->assertCount(0, RoomType::query()->accessibleBy($reception)->get());
    }
}
