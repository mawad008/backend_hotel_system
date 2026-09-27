<?php

namespace App\Domain\DigitalAccess\Provider\Data;

use App\Domain\DigitalAccess\Provider\SimulationDirective;
use App\Domain\DigitalAccess\Provider\Support\AccessSensitiveDataGuard;

/**
 * Phase 7 — the input to DigitalAccessProviderInterface::issue.
 *
 * A pure application-layer value object: no Eloquent model, no repository,
 * no persistence concern. The workflow layer builds one of these from an
 * AccessGrant / Reservation and is responsible for persisting whatever the
 * provider returns.
 *
 * `grantReference` is an opaque, caller-chosen correlation string (the
 * grant's idempotency key). The dummy provider uses it purely as a
 * deterministic seed for the provider reference and PIN it returns.
 *
 * Carries NO credential and NO secret — the provider MINTS the credential.
 */
final class AccessIssueRequest
{
    /**
     * @param  array<string, scalar|null>  $metadata  safe, non-secret context only
     */
    public function __construct(
        public readonly string $grantReference,
        public readonly string $accessMode,
        public readonly ?SimulationDirective $directive = null,
        public readonly array $metadata = [],
    ) {
        AccessSensitiveDataGuard::assertClean($this->metadata, 'issue request');
    }
}
