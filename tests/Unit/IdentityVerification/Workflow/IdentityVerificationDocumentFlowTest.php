<?php

namespace Tests\Unit\IdentityVerification\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationActionNotAllowedException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationNotAllowedException;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

class IdentityVerificationDocumentFlowTest extends IdentityVerificationWorkflowTestCase
{
    public function test_first_document_submission_creates_a_session_and_attempt(): void
    {
        $reservation = $this->reservation();

        $session = $this->makeService()->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());

        $this->assertSame(IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED, $session->status);
        $this->assertSame($reservation->hotel_id, $session->hotel_id);
        $this->assertSame($reservation->guest_id, $session->guest_id);
        $this->assertSame(0, $session->attempts);
        $this->assertSame('dummy', $session->provider);

        $attempt = IdentityVerificationAttempt::sole();
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertNotNull($attempt->document_path);
        $this->assertStringStartsWith('identity-verification/', $attempt->document_path);
        Storage::disk('local')->assertExists($attempt->document_path);

        $this->assertTrue(AuditLog::where('action', 'identity_verification.session_started')->exists());
        $this->assertTrue(AuditLog::where('action', 'identity_verification.document_submitted')->exists());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonDepositHeldStatuses(): array
    {
        return [
            'pending' => [Reservation::STATUS_PENDING],
            'verified' => [Reservation::STATUS_VERIFIED],
            'checked_in' => [Reservation::STATUS_CHECKED_IN],
            'cancelled' => [Reservation::STATUS_CANCELLED],
        ];
    }

    #[DataProvider('nonDepositHeldStatuses')]
    public function test_document_submission_requires_a_deposit_held_reservation(string $status): void
    {
        $reservation = $this->reservation($status);

        $this->expectException(IdentityVerificationNotAllowedException::class);

        $this->makeService()->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());

        $this->assertDatabaseCount('identity_verification_sessions', 0);
    }

    public function test_re_uploading_a_document_replaces_the_current_attempt_file(): void
    {
        $reservation = $this->reservation();
        $service = $this->makeService();

        $service->submitDocument($reservation, $this->image('doc-1.jpg'), 'passport', claim: $this->claim());
        $firstPath = IdentityVerificationAttempt::sole()->document_path;

        $service->submitDocument($reservation, $this->image('doc-2.png'), 'national id', claim: $this->claim());

        $attempt = IdentityVerificationAttempt::sole();
        $this->assertNotSame($firstPath, $attempt->document_path);
        $this->assertSame('national id', $attempt->document_type);
        $this->assertSame(1, IdentityVerificationAttempt::count());
        Storage::disk('local')->assertMissing($firstPath);
    }

    public function test_document_submission_is_rejected_after_the_selfie_step(): void
    {
        config(['verification.thresholds.auto_approve' => 95]);
        $reservation = $this->reservation();
        $service = $this->makeService();
        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('selfie.jpg'), directive: SimulationDirective::MediumMatch);

        // Session is now pending_manual_review — not a new-attempt status.
        $this->expectException(IdentityVerificationActionNotAllowedException::class);
        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
    }

    public function test_a_new_attempt_from_retry_allowed_increments_the_attempt_number(): void
    {
        config([
            'verification.max_retries' => 2,
            'verification.thresholds.auto_approve' => 80,
        ]);
        $reservation = $this->reservation();
        $service = $this->makeService();

        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('selfie.jpg'), directive: SimulationDirective::Error);
        $this->assertSame(IdentityVerificationSession::STATUS_RETRY_ALLOWED, IdentityVerificationSession::sole()->status);

        $session = $service->submitDocument($reservation, $this->image('doc-2.jpg'), 'passport', claim: $this->claim());

        $this->assertSame(IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED, $session->status);
        $this->assertSame(2, IdentityVerificationAttempt::max('attempt_number'));
    }
}
