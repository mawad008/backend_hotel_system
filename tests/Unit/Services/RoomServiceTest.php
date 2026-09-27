<?php

namespace Tests\Unit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Exceptions\InvalidRoomStatusTransitionException;
use App\Domain\Inventory\Exceptions\RoomTypeHotelMismatchException;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\EloquentRoomRepository;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use App\Domain\Inventory\Services\RoomService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoomServiceTest extends TestCase
{
    private RoomService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RoomService(
            new EloquentRoomRepository,
            new EloquentRoomTypeRepository,
            app(AuditLogger::class),
        );
    }

    public function test_create_forces_hotel_id_and_available_status_regardless_of_input(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $room = $this->service->create($hotel, [
            'hotel_id' => $otherHotel->id, // must be ignored
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'status' => 'booked', // must be ignored — always starts available
        ], $owner);

        $this->assertSame($hotel->id, $room->hotel_id);
        $this->assertSame('available', $room->status);
    }

    public function test_create_writes_an_audit_log_entry(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $room = $this->service->create($hotel, [
            'room_type_id' => $roomType->id,
            'room_number' => '202',
        ], $owner);

        $log = AuditLog::where('action', 'room.created')->first();

        $this->assertNotNull($log);
        $this->assertSame($hotel->id, $log->hotel_id);
        $this->assertSame($room->id, $log->auditable_id);
    }

    public function test_create_rejects_a_room_type_from_a_different_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $foreignRoomType = RoomType::factory()->create(['hotel_id' => $otherHotel->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->expectException(RoomTypeHotelMismatchException::class);

        $this->service->create($hotel, [
            'room_type_id' => $foreignRoomType->id,
            'room_number' => '303',
        ], $owner);
    }

    public function test_create_does_not_persist_anything_when_room_type_mismatch_occurs(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $foreignRoomType = RoomType::factory()->create(['hotel_id' => $otherHotel->id]);
        $owner = User::factory()->groupOwner()->create();

        try {
            $this->service->create($hotel, [
                'room_type_id' => $foreignRoomType->id,
                'room_number' => '404',
            ], $owner);
        } catch (RoomTypeHotelMismatchException) {
            // expected
        }

        $this->assertSame(0, Room::count());
        $this->assertDatabaseMissing('rooms', ['room_number' => '404']);
        $this->assertNull(AuditLog::where('action', 'room.created')->first());
    }

    public function test_update_rejects_changing_room_type_to_one_from_a_different_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $foreignRoomType = RoomType::factory()->create(['hotel_id' => $otherHotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->expectException(RoomTypeHotelMismatchException::class);

        $this->service->update($room, ['room_type_id' => $foreignRoomType->id], $owner);
    }

    public function test_update_cannot_change_hotel_id_or_status(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $updated = $this->service->update($room, [
            'hotel_id' => $otherHotel->id,
            'status' => 'booked',
            'room_number' => '505',
        ], $owner);

        $this->assertSame($hotel->id, $updated->hotel_id);
        $this->assertSame('available', $updated->status);
        $this->assertSame('505', $updated->room_number);
    }

    public function test_available_to_under_maintenance_transition_succeeds(): void
    {
        $room = Room::factory()->create(['status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $result = $this->service->transitionStatus($room, 'under_maintenance', $owner);

        $this->assertSame('under_maintenance', $result->fresh()->status);
    }

    public function test_under_maintenance_to_available_transition_succeeds(): void
    {
        $room = Room::factory()->underMaintenance()->create();
        $owner = User::factory()->groupOwner()->create();

        $result = $this->service->transitionStatus($room, 'available', $owner);

        $this->assertSame('available', $result->fresh()->status);
    }

    public function test_transition_writes_an_audit_log_entry(): void
    {
        $room = Room::factory()->create(['status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        $this->service->transitionStatus($room, 'under_maintenance', $owner);

        $log = AuditLog::where('action', 'room.status_updated')->first();

        $this->assertNotNull($log);
        $this->assertSame('available', $log->before['status']);
        $this->assertSame('under_maintenance', $log->after['status']);
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transitions_are_rejected(string $from, string $to): void
    {
        $room = Room::factory()->create(['status' => $from]);
        $owner = User::factory()->groupOwner()->create();

        $this->expectException(InvalidRoomStatusTransitionException::class);

        $this->service->transitionStatus($room, $to, $owner);
    }

    public static function invalidTransitions(): array
    {
        return [
            'available -> booked' => ['available', 'booked'],
            'under_maintenance -> booked' => ['under_maintenance', 'booked'],
            'booked -> available' => ['booked', 'available'],
            'booked -> under_maintenance' => ['booked', 'under_maintenance'],
            'available -> available (no-op not allowed)' => ['available', 'available'],
        ];
    }

    public function test_invalid_transition_does_not_persist_or_audit_anything(): void
    {
        $room = Room::factory()->create(['status' => 'available']);
        $owner = User::factory()->groupOwner()->create();

        try {
            $this->service->transitionStatus($room, 'booked', $owner);
        } catch (InvalidRoomStatusTransitionException) {
            // expected
        }

        $this->assertSame('available', $room->fresh()->status);
        $this->assertNull(AuditLog::where('action', 'room.status_updated')->first());
    }
}
