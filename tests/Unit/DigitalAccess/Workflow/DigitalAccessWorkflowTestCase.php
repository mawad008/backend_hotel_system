<?php

namespace Tests\Unit\DigitalAccess\Workflow;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\DigitalAccess\Provider\Contracts\DigitalAccessProviderInterface;
use App\Domain\DigitalAccess\Repositories\EloquentAccessGrantRepository;
use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationSessionRepository;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\EloquentRoomRepository;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Repositories\EloquentPaymentRepository;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\EloquentGuestRepository;
use App\Domain\Reservation\Repositories\EloquentReservationRepository;
use App\Domain\Reservation\Services\ReservationService;
use Tests\TestCase;

abstract class DigitalAccessWorkflowTestCase extends TestCase
{
    protected function makeService(
        ?DigitalAccessProviderInterface $provider = null,
        ?AuditLogger $auditLogger = null,
    ): DigitalAccessService {
        $auditLogger ??= app(AuditLogger::class);

        return new DigitalAccessService(
            $provider ?? app(DigitalAccessProviderInterface::class),
            new EloquentAccessGrantRepository,
            new EloquentReservationRepository,
            new EloquentPaymentRepository,
            new EloquentIdentityVerificationSessionRepository,
            new ReservationService(
                new EloquentReservationRepository,
                new EloquentRoomTypeRepository,
                new EloquentRoomRepository,
                new EloquentGuestRepository,
                $auditLogger,
            ),
            $auditLogger,
        );
    }

    /**
     * A Reservation that has passed payment + identity verification and is
     * ready for check-in: status VERIFIED, Payment HOLD_ACTIVE, identity
     * session AUTO_APPROVED.
     */
    protected function verifiedReservation(?Hotel $hotel = null, bool $withRoom = true): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        $factory = Reservation::factory()->state([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_VERIFIED,
            'check_out' => now()->addDays(3)->format('Y-m-d'),
        ]);

        if ($withRoom) {
            $factory = $factory->withRoom();
        }

        $reservation = $factory->create();

        Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $hotel->id,
        ]);

        IdentityVerificationSession::factory()->autoApproved()->create([
            'reservation_id' => $reservation->id,
        ]);

        return $reservation;
    }
}
