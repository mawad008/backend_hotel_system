<?php

namespace App\Domain\DigitalAccess\Provider\Data;

use App\Domain\DigitalAccess\Provider\SimulationDirective;
use App\Domain\DigitalAccess\Provider\Support\AccessSensitiveDataGuard;

/**
 * Phase 7 — the input to a follow-up provider operation that acts on an
 * existing credential: revoke.
 *
 * `providerReference` is the reference the earlier issue() result returned.
 * Like AccessIssueRequest this is a pure value object — no model, no
 * repository, no persistence, no secret.
 */
final class AccessOperationRequest
{
    /**
     * @param  array<string, scalar|null>  $metadata  safe, non-secret context only
     */
    public function __construct(
        public readonly string $providerReference,
        public readonly ?SimulationDirective $directive = null,
        public readonly array $metadata = [],
    ) {
        AccessSensitiveDataGuard::assertClean($this->metadata, 'operation request');
    }
}
