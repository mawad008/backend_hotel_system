<?php

namespace App\Domain\IdentityVerification\Provider;

use App\Domain\IdentityVerification\Provider\Contracts\IdentityVerificationProviderInterface;
use App\Domain\IdentityVerification\Provider\Data\VerificationRequest;
use App\Domain\IdentityVerification\Provider\Data\VerificationResult;

/**
 * Phase 6 — the deterministic dummy identity verification provider
 * (Phase 0 §10/§15, R50: "all third-party integrations dummy/mocked this
 * phase").
 *
 * It is a pure provider-boundary simulation:
 *  - no randomness, no sleep/delay, no time-based behaviour
 *  - no external HTTP, no real credentials, no OCR, no biometric processing
 *  - no Eloquent model, no repository, no DB access, no audit write, no file
 *
 * Every match outcome is a pure function of (simulation directive,
 * caller-supplied attempt reference). The directive is either given
 * explicitly on the request or falls back to the configured default
 * (deterministic high match). The same inputs always produce an equivalent
 * result.
 *
 * The score it returns for each band is a fixed SIMULATION value, not a
 * business threshold — the auto-approve / manual-review decision is made by
 * the workflow layer against config('verification.thresholds'), never here.
 */
final class DummyIdentityVerificationProvider implements IdentityVerificationProviderInterface
{
    /**
     * Hex chars of SHA-256 kept for a provider reference. 24 hex = 96 bits,
     * plenty to keep distinct test scenarios apart.
     */
    private const REFERENCE_HEX_LENGTH = 24;

    /**
     * Fixed simulation score per band. Deliberately spread across the 0..100
     * scale so a test can put a configured threshold on either side of any
     * band. NOT a business value — see the class docblock.
     *
     * @var array<string, int|null>
     */
    private const SIMULATION_SCORES = [
        MatchOutcome::HighMatch->value => 90,
        MatchOutcome::MediumMatch->value => 55,
        MatchOutcome::LowMatch->value => 20,
        MatchOutcome::Error->value => null,
    ];

    public function __construct(
        private readonly SimulationDirective $defaultDirective = SimulationDirective::DEFAULT,
    ) {}

    public function verify(VerificationRequest $request): VerificationResult
    {
        $directive = $request->directive ?? $this->defaultDirective;
        $outcome = MatchOutcome::forDirective($directive);
        $score = self::SIMULATION_SCORES[$outcome->value];

        return new VerificationResult(
            outcome: $outcome,
            score: $score,
            providerReference: $this->reference($request->attemptReference),
            providerCode: sprintf('dummy_idv_%s', $outcome->value),
            message: $this->message($outcome),
            context: $request->metadata,
        );
    }

    private function reference(string $seed): string
    {
        $hex = substr(hash('sha256', 'idv|'.$seed), 0, self::REFERENCE_HEX_LENGTH);

        return sprintf('dummy_idv_%s', $hex);
    }

    private function message(MatchOutcome $outcome): string
    {
        return match ($outcome) {
            MatchOutcome::HighMatch => 'Dummy provider reports a high-confidence match.',
            MatchOutcome::MediumMatch => 'Dummy provider reports a medium-confidence match.',
            MatchOutcome::LowMatch => 'Dummy provider reports a low-confidence match.',
            MatchOutcome::Error => 'Dummy provider could not complete the match.',
        };
    }
}
