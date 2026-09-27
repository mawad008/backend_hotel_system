<?php

namespace Tests\Unit\IdentityVerification\Workflow;

use App\Domain\IdentityVerification\Exceptions\IdentityVerificationIdempotencyKeyConflictException;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityVerificationProviderInterface;
use App\Domain\IdentityVerification\Provider\Data\VerificationRequest;
use App\Domain\IdentityVerification\Provider\Data\VerificationResult;
use App\Domain\IdentityVerification\Provider\MatchOutcome;
use App\Domain\IdentityVerification\Provider\SimulationDirective;

class IdentityVerificationIdempotencyTest extends IdentityVerificationWorkflowTestCase
{
    /**
     * A provider that counts how many times it was invoked.
     */
    private function countingProvider(): IdentityVerificationProviderInterface
    {
        return new class implements IdentityVerificationProviderInterface
        {
            public int $calls = 0;

            public function verify(VerificationRequest $request): VerificationResult
            {
                $this->calls++;

                return new VerificationResult(
                    outcome: MatchOutcome::HighMatch,
                    score: 90,
                    providerReference: 'dummy_idv_'.substr(md5($request->attemptReference), 0, 16),
                    providerCode: 'dummy_idv_high_match',
                    message: 'ok',
                );
            }
        };
    }

    public function test_same_idempotency_key_replays_without_a_second_provider_call(): void
    {
        config(['verification.thresholds.auto_approve' => 80]);
        $provider = $this->countingProvider();
        $service = $this->makeService($provider);
        $reservation = $this->reservation();

        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());

        $first = $service->submitSelfie($reservation, $this->image('s.jpg'), idempotencyKey: 'idv-key-1', directive: SimulationDirective::HighMatch);
        $second = $service->submitSelfie($reservation, $this->image('s.jpg'), idempotencyKey: 'idv-key-1', directive: SimulationDirective::HighMatch);

        $this->assertSame(1, $provider->calls);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(IdentityVerificationSession::STATUS_AUTO_APPROVED, $second->status);
        $this->assertSame(1, IdentityVerificationAttempt::count());
        $this->assertSame(1, IdentityVerificationDecision::count());
    }

    public function test_idempotency_key_reused_across_verifications_is_a_conflict(): void
    {
        config(['verification.thresholds.auto_approve' => 80]);
        $service = $this->makeService();

        $a = $this->reservation();
        $service->submitDocument($a, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($a, $this->image('s.jpg'), idempotencyKey: 'shared', directive: SimulationDirective::HighMatch);

        $b = $this->reservation();
        $service->submitDocument($b, $this->image('doc.jpg'), 'passport', claim: $this->claim());

        $this->expectException(IdentityVerificationIdempotencyKeyConflictException::class);
        $service->submitSelfie($b, $this->image('s.jpg'), idempotencyKey: 'shared', directive: SimulationDirective::HighMatch);
    }

    public function test_no_idempotency_key_generates_one_and_still_matches(): void
    {
        config(['verification.thresholds.auto_approve' => 80]);
        $service = $this->makeService();
        $reservation = $this->reservation();

        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('s.jpg'), directive: SimulationDirective::HighMatch);

        $this->assertNotNull(IdentityVerificationAttempt::sole()->idempotency_key);
    }
}
