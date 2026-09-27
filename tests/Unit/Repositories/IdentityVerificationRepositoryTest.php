<?php

namespace Tests\Unit\Repositories;

use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationAttemptRepository;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationDecisionRepository;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationSessionRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdentityVerificationRepositoryTest extends TestCase
{
    public function test_session_repository_find_by_reservation(): void
    {
        $repo = new EloquentIdentityVerificationSessionRepository;
        $session = IdentityVerificationSession::factory()->create();

        $this->assertSame($session->id, $repo->findByReservation($session->reservation_id)?->id);
        $this->assertNull($repo->findByReservation(999999));
    }

    public function test_session_repository_lock_helpers_require_a_transaction(): void
    {
        $repo = new EloquentIdentityVerificationSessionRepository;
        $session = IdentityVerificationSession::factory()->create();

        DB::transaction(function () use ($repo, $session) {
            $this->assertSame($session->id, $repo->findForUpdate($session->id)?->id);
            $this->assertSame($session->id, $repo->findByReservationForUpdate($session->reservation_id)?->id);
        });
    }

    public function test_attempt_repository_lookups(): void
    {
        $repo = new EloquentIdentityVerificationAttemptRepository;
        $session = IdentityVerificationSession::factory()->create();
        $one = IdentityVerificationAttempt::factory()->create(['session_id' => $session->id, 'attempt_number' => 1]);
        $two = IdentityVerificationAttempt::factory()->create(['session_id' => $session->id, 'attempt_number' => 2]);

        $this->assertSame($two->id, $repo->latestForSession($session->id)?->id);
        $this->assertSame($one->id, $repo->findByIdempotencyKey($one->idempotency_key)?->id);
    }

    public function test_decision_repository_appends_and_reads_latest(): void
    {
        $repo = new EloquentIdentityVerificationDecisionRepository;
        $session = IdentityVerificationSession::factory()->create();

        $repo->create([
            'session_id' => $session->id,
            'type' => IdentityVerificationDecision::TYPE_AUTOMATED,
            'result' => IdentityVerificationDecision::RESULT_MANUAL_REVIEW_REQUIRED,
        ]);
        $latest = $repo->create([
            'session_id' => $session->id,
            'type' => IdentityVerificationDecision::TYPE_MANUAL,
            'result' => IdentityVerificationDecision::RESULT_STAFF_APPROVED,
        ]);

        $this->assertSame($latest->id, $repo->latestForSession($session->id)?->id);
    }
}
