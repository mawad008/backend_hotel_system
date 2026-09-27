<?php

namespace Tests\Unit\IdentityVerification\Provider;

use App\Domain\IdentityVerification\Provider\Data\VerificationRequest;
use App\Domain\IdentityVerification\Provider\DummyIdentityVerificationProvider;
use App\Domain\IdentityVerification\Provider\Exceptions\UnsupportedIdentityVerificationSimulationDirectiveException;
use App\Domain\IdentityVerification\Provider\MatchOutcome;
use App\Domain\IdentityVerification\Provider\SimulationDirective;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 6 — the deterministic dummy identity verification provider
 * (Phase 0 §10/§15).
 */
class DummyIdentityVerificationProviderTest extends TestCase
{
    /**
     * @return array<string, array{SimulationDirective, MatchOutcome, int|null}>
     */
    public static function directiveOutcomes(): array
    {
        return [
            'high' => [SimulationDirective::HighMatch, MatchOutcome::HighMatch, 90],
            'medium' => [SimulationDirective::MediumMatch, MatchOutcome::MediumMatch, 55],
            'low' => [SimulationDirective::LowMatch, MatchOutcome::LowMatch, 20],
            'error' => [SimulationDirective::Error, MatchOutcome::Error, null],
        ];
    }

    #[DataProvider('directiveOutcomes')]
    public function test_each_directive_maps_to_its_deterministic_outcome_and_score(
        SimulationDirective $directive,
        MatchOutcome $expectedOutcome,
        ?int $expectedScore,
    ): void {
        $provider = new DummyIdentityVerificationProvider;

        $result = $provider->verify(new VerificationRequest('attempt-1', directive: $directive));

        $this->assertSame($expectedOutcome, $result->outcome);
        $this->assertSame($expectedScore, $result->score);
    }

    public function test_default_directive_is_used_when_none_is_supplied(): void
    {
        $provider = new DummyIdentityVerificationProvider(SimulationDirective::LowMatch);

        $result = $provider->verify(new VerificationRequest('attempt-1'));

        $this->assertSame(MatchOutcome::LowMatch, $result->outcome);
    }

    public function test_provider_reference_is_deterministic_for_the_same_seed(): void
    {
        $provider = new DummyIdentityVerificationProvider;

        $a = $provider->verify(new VerificationRequest('same-seed', directive: SimulationDirective::HighMatch));
        $b = $provider->verify(new VerificationRequest('same-seed', directive: SimulationDirective::HighMatch));
        $c = $provider->verify(new VerificationRequest('other-seed', directive: SimulationDirective::HighMatch));

        $this->assertSame($a->providerReference, $b->providerReference);
        $this->assertNotSame($a->providerReference, $c->providerReference);
        $this->assertStringStartsWith('dummy_idv_', $a->providerReference);
    }

    public function test_result_carries_no_secret_or_pii(): void
    {
        $provider = new DummyIdentityVerificationProvider;

        $result = $provider->verify(new VerificationRequest('attempt-1', documentType: 'passport'));

        foreach ([$result->message, $result->providerCode, json_encode($result->context)] as $text) {
            $this->assertStringNotContainsStringIgnoringCase('secret', (string) $text);
            $this->assertStringNotContainsStringIgnoringCase('password', (string) $text);
        }
    }

    public function test_request_rejects_sensitive_metadata_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VerificationRequest('attempt-1', metadata: ['document_number' => 'X123456']);
    }

    public function test_error_outcome_has_no_score(): void
    {
        $provider = new DummyIdentityVerificationProvider;

        $result = $provider->verify(new VerificationRequest('a', directive: SimulationDirective::Error));

        $this->assertTrue($result->isError());
        $this->assertNull($result->score);
    }

    public function test_from_config_rejects_an_unknown_directive(): void
    {
        $this->expectException(UnsupportedIdentityVerificationSimulationDirectiveException::class);

        SimulationDirective::fromConfig('sometimes');
    }
}
