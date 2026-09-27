<?php

namespace Tests\Unit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use App\Domain\Inventory\Services\RoomTypeService;
use Tests\TestCase;

class RoomTypeServiceTest extends TestCase
{
    private RoomTypeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RoomTypeService(
            new EloquentRoomTypeRepository,
            app(AuditLogger::class),
        );
    }

    public function test_create_forces_hotel_id_from_the_given_hotel_regardless_of_input(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $roomType = $this->service->create($hotel, [
            'hotel_id' => $otherHotel->id, // must be ignored
            'name' => 'Deluxe Double',
            'base_price' => 100,
            'capacity' => 2,
        ], $owner);

        $this->assertSame($hotel->id, $roomType->hotel_id);
        $this->assertNotSame($otherHotel->id, $roomType->hotel_id);
    }

    public function test_create_writes_an_audit_log_entry(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = User::factory()->groupOwner()->create();

        $roomType = $this->service->create($hotel, [
            'name' => 'Suite',
            'base_price' => 200,
            'capacity' => 4,
        ], $owner);

        $log = AuditLog::where('action', 'room-type.created')->first();

        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->actor_id);
        $this->assertSame($hotel->id, $log->hotel_id);
        $this->assertSame($roomType->id, $log->auditable_id);
        $this->assertSame('Suite', $log->after['name']);
    }

    public function test_update_records_before_and_after_state(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Old Name']);
        $owner = User::factory()->groupOwner()->create();

        $updated = $this->service->update($roomType, ['name' => 'New Name'], $owner);

        $this->assertSame('New Name', $updated->name);

        $log = AuditLog::where('action', 'room-type.updated')->first();
        $this->assertSame('Old Name', $log->before['name']);
        $this->assertSame('New Name', $log->after['name']);
    }

    public function test_update_cannot_change_hotel_id_or_is_active(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);
        $owner = User::factory()->groupOwner()->create();

        $updated = $this->service->update($roomType, [
            'hotel_id' => $otherHotel->id,
            'is_active' => false,
            'name' => 'Renamed',
        ], $owner);

        $this->assertSame($hotel->id, $updated->hotel_id);
        $this->assertTrue($updated->is_active);
        $this->assertSame('Renamed', $updated->name);
    }

    public function test_deactivate_flips_is_active_without_touching_rooms(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);
        $room = Room::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => 'available',
        ]);
        $owner = User::factory()->groupOwner()->create();

        $deactivated = $this->service->deactivate($roomType, $owner);

        $this->assertFalse($deactivated->is_active);
        $this->assertSame('available', $room->fresh()->status);
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);

        $log = AuditLog::where('action', 'room-type.deactivated')->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->after['is_active']);
    }

    public function test_activate_flips_is_active_back_on(): void
    {
        $roomType = RoomType::factory()->inactive()->create();
        $owner = User::factory()->groupOwner()->create();

        $activated = $this->service->activate($roomType, $owner);

        $this->assertTrue($activated->is_active);
        $this->assertNotNull(AuditLog::where('action', 'room-type.activated')->first());
    }

    /**
     * Regression test for Phase 2D bug #1: update() used to return the
     * original, count-less $roomType instance instead of the
     * repository's refreshed one.
     */
    public function test_update_returns_the_refreshed_instance_with_integer_counts(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $updated = $this->service->update($roomType, ['capacity' => 3], $owner);

        $this->assertIsInt($updated->rooms_count);
        $this->assertIsInt($updated->available_rooms_count);
        $this->assertIsInt($updated->maintenance_rooms_count);
        $this->assertSame(1, $updated->rooms_count);
        $this->assertSame(1, $updated->available_rooms_count);
    }

    /**
     * Regression test for Phase 2D bug #1 (activate/deactivate paths).
     */
    public function test_activate_and_deactivate_return_refreshed_instances_with_integer_counts(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->underMaintenance()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $deactivated = $this->service->deactivate($roomType, $owner);
        $this->assertIsInt($deactivated->rooms_count);
        $this->assertIsInt($deactivated->maintenance_rooms_count);
        $this->assertSame(1, $deactivated->maintenance_rooms_count);

        $activated = $this->service->activate($roomType, $owner);
        $this->assertIsInt($activated->rooms_count);
        $this->assertIsInt($activated->maintenance_rooms_count);
        $this->assertSame(1, $activated->maintenance_rooms_count);
    }

    /**
     * Regression test for Phase 2D bug #1: the audit "after" snapshot
     * must reflect the refreshed instance, not the stale pre-update one.
     */
    public function test_update_audit_after_snapshot_reflects_the_refreshed_instance(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $this->service->update($roomType, ['capacity' => 3], $owner);

        $log = AuditLog::where('action', 'room-type.updated')->first();
        $this->assertSame(1, $log->after['rooms_count']);
        $this->assertSame(1, $log->after['available_rooms_count']);
    }
}
