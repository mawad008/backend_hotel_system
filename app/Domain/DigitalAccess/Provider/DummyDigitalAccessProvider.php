<?php

namespace App\Domain\DigitalAccess\Provider;

use App\Domain\DigitalAccess\Provider\Contracts\DigitalAccessProviderInterface;
use App\Domain\DigitalAccess\Provider\Data\AccessIssueRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessOperationRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessResult;

/**
 * Phase 7 — the deterministic dummy digital access provider (Phase 0
 * §11/§15, R50: "all third-party integrations dummy/mocked this phase").
 *
 * It is a pure provider-boundary simulation:
 *  - no randomness, no sleep/delay, no time-based behaviour
 *  - no external HTTP, no real credentials, no smart-lock integration
 *  - no Eloquent model, no repository, no DB access, no audit write
 *  - no authorization / hotel-scope / eligibility logic (§11)
 *
 * Every outcome is a pure function of (operation, simulation directive,
 * caller-supplied grant reference). The directive is either given explicitly
 * on the request or falls back to the configured default (deterministic
 * success). The same inputs always produce an equivalent result.
 *
 * ══ SECURITY NOTE ══
 * The PIN this dummy returns is a DETERMINISTIC function of the grant
 * reference — it is a simulation value, NOT a secure credential. A real
 * provider MUST mint a cryptographically-random code. This is called out in
 * the Phase 7 report as an explicit dummy-only property.
 */
final class DummyDigitalAccessProvider implements DigitalAccessProviderInterface
{
    /**
     * Hex chars of SHA-256 kept for a provider reference. 24 hex = 96 bits.
     */
    private const REFERENCE_HEX_LENGTH = 24;

    public function __construct(
        private readonly SimulationDirective $defaultDirective = SimulationDirective::DEFAULT,
    ) {}

    public function issue(AccessIssueRequest $request): AccessResult
    {
        $directive = $request->directive ?? $this->defaultDirective;
        $status = AccessResultStatus::forIssueDirective($directive);

        return new AccessResult(
            status: $status,
            providerReference: $this->reference('issue', $request->grantReference),
            credential: $status === AccessResultStatus::Active
                ? $this->deterministicPin($request->grantReference)
                : null,
            providerCode: sprintf('dummy_access_issue_%s', $status->value),
            message: $status === AccessResultStatus::Active
                ? 'Dummy provider issued and activated an access credential.'
                : 'Dummy provider could not issue an access credential.',
            context: $request->metadata,
        );
    }

    public function revoke(AccessOperationRequest $request): AccessResult
    {
        $directive = $request->directive ?? $this->defaultDirective;
        $status = AccessResultStatus::forRevokeDirective($directive);

        return new AccessResult(
            status: $status,
            providerReference: $this->reference('revoke', $request->providerReference),
            credential: null,
            providerCode: sprintf('dummy_access_revoke_%s', $status->value),
            message: $status === AccessResultStatus::Revoked
                ? 'Dummy provider revoked the access credential.'
                : 'Dummy provider could not revoke the access credential.',
            context: $request->metadata,
        );
    }

    private function reference(string $operation, string $seed): string
    {
        $hex = substr(hash('sha256', 'access|'.$operation.'|'.$seed), 0, self::REFERENCE_HEX_LENGTH);

        return sprintf('dummy_access_%s_%s', $operation, $hex);
    }

    /**
     * A deterministic 6-digit code derived from the seed. SIMULATION ONLY —
     * see the class docblock.
     */
    private function deterministicPin(string $seed): string
    {
        $slice = substr(hash('sha256', 'access-pin|'.$seed), 0, 8);

        return str_pad((string) (hexdec($slice) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
