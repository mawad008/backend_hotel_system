<?php

namespace App\Domain\IdentityVerification\Provider\Data;

use App\Domain\IdentityVerification\Provider\SimulationDirective;
use App\Domain\IdentityVerification\Provider\Support\IdentitySensitiveDataGuard;

/**
 * Phase 6 — the input to IdentityVerificationProviderInterface::verify.
 *
 * A pure application-layer value object: no Eloquent model, no repository,
 * no persistence concern. The workflow layer builds one of these from a
 * verification attempt and is responsible for persisting whatever the
 * provider returns.
 *
 * `attemptReference` is an opaque, caller-chosen correlation string (the
 * attempt's idempotency key). The dummy provider uses it purely as a
 * deterministic seed for the provider reference it returns.
 *
 * Deliberately carries NO document bytes, NO document number, NO selfie —
 * the provider is a boundary simulation and receives references only.
 */
final class VerificationRequest
{
    /**
     * @param  array<string, scalar|null>  $metadata  safe, non-sensitive context only
     */
    public function __construct(
        public readonly string $attemptReference,
        public readonly ?string $documentType = null,
        public readonly ?SimulationDirective $directive = null,
        public readonly array $metadata = [],
    ) {
        IdentitySensitiveDataGuard::assertClean($this->metadata, 'verification request');
    }
}
