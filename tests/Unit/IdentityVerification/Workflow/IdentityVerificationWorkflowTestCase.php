<?php

namespace Tests\Unit\IdentityVerification\Workflow;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityVerification\DocumentCheck\IdentityClaim;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityVerificationProviderInterface;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use App\Domain\IdentityVerification\Services\IdentityDocumentCheckService;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationAttemptRepository;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationDecisionRepository;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationSessionRepository;
use App\Domain\IdentityVerification\Services\IdentityVerificationService;
use App\Domain\IdentityVerification\Support\IdentityFileStore;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\EloquentRoomRepository;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\EloquentGuestRepository;
use App\Domain\Reservation\Repositories\EloquentReservationRepository;
use App\Domain\Reservation\Services\ReservationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class IdentityVerificationWorkflowTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function makeService(
        ?IdentityVerificationProviderInterface $provider = null,
        ?AuditLogger $auditLogger = null,
        ?IdentityDocumentProviderInterface $documentProvider = null,
    ): IdentityVerificationService {
        $auditLogger ??= app(AuditLogger::class);

        return new IdentityVerificationService(
            $provider ?? app(IdentityVerificationProviderInterface::class),
            new EloquentIdentityVerificationSessionRepository,
            new EloquentIdentityVerificationAttemptRepository,
            new EloquentIdentityVerificationDecisionRepository,
            new EloquentReservationRepository,
            new ReservationService(
                new EloquentReservationRepository,
                new EloquentRoomTypeRepository,
                new EloquentRoomRepository,
                new EloquentGuestRepository,
                $auditLogger,
            ),
            new IdentityFileStore,
            $auditLogger,
            new IdentityDocumentCheckService($documentProvider ?? new DummyIdentityDocumentProvider, new IdentityFileStore),
        );
    }

    protected function reservation(
        string $status = Reservation::STATUS_DEPOSIT_HELD,
        ?Hotel $hotel = null,
    ): Reservation {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => $status,
        ]);
    }

    /** The claim that matches the dummy provider's synthetic specimen passport. */
    protected function claim(): IdentityClaim
    {
        $s = DummyIdentityDocumentProvider::SPECIMEN;

        return new IdentityClaim(
            fullName: $s['given'].' '.$s['surname'],
            documentNumber: $s['document_number'],
            dateOfBirth: new \DateTimeImmutable($s['date_of_birth']),
        );
    }

    protected function image(string $name = 'x.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }
}
