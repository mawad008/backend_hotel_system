<?php

namespace Tests\Unit\DigitalAccess\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Provider\SimulationDirective;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;

class DigitalAccessExpiryTest extends DigitalAccessWorkflowTestCase
{
    public function test_status_read_lazily_expires_an_active_grant_past_its_check_out(): void
    {
        $reservation = $this->verifiedReservation();
        $service = $this->makeService();
        $service->checkIn($reservation, SimulationDirective::Success);

        // The stay is now over.
        AccessGrant::query()->update(['expires_at' => now()->subMinute()]);

        $grant = $service->currentStatusFor($reservation->fresh());

        $this->assertSame(AccessGrant::STATUS_EXPIRED, $grant->status);
        $this->assertNull($grant->credential);
        $this->assertTrue(AuditLog::where('action', 'digital_access.expired')->exists());
    }

    public function test_status_read_does_not_expire_a_grant_still_within_its_window(): void
    {
        $reservation = $this->verifiedReservation();
        $service = $this->makeService();
        $service->checkIn($reservation, SimulationDirective::Success);

        $grant = $service->currentStatusFor($reservation->fresh());

        $this->assertSame(AccessGrant::STATUS_ACTIVE, $grant->status);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $grant->credential);
    }

    public function test_expiry_is_idempotent_across_repeated_reads(): void
    {
        $reservation = $this->verifiedReservation();
        $service = $this->makeService();
        $service->checkIn($reservation, SimulationDirective::Success);
        AccessGrant::query()->update(['expires_at' => now()->subMinute()]);

        $service->currentStatusFor($reservation->fresh());
        $service->currentStatusFor($reservation->fresh());

        $this->assertSame(1, AuditLog::where('action', 'digital_access.expired')->count());
    }

    public function test_status_for_a_reservation_without_a_grant_is_a_transient_not_issued(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_VERIFIED,
        ]);

        $grant = $this->makeService()->currentStatusFor($reservation);

        $this->assertFalse($grant->exists);
        $this->assertSame(AccessGrant::STATUS_NOT_ISSUED, $grant->status);
        $this->assertDatabaseCount('access_grants', 0);
    }
}
