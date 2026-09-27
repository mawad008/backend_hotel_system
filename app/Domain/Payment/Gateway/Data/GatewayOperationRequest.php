<?php

namespace App\Domain\Payment\Gateway\Data;

use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Gateway\Support\SensitiveDataGuard;

/**
 * Phase 5B — the input to every follow-up provider operation that acts on
 * an existing hold: cancelHold, capture, settle and verify.
 *
 * `providerReference` is the reference the earlier initiateHold result
 * returned. The dummy gateway uses it as the deterministic seed for the
 * new operation's own reference.
 *
 * Like GatewayHoldRequest this is a pure value object — no model, no
 * repository, no persistence.
 */
final class GatewayOperationRequest
{
    /**
     * @param  array<string, scalar|null>  $metadata  safe, non-sensitive context only
     */
    public function __construct(
        public readonly string $providerReference,
        public readonly ?SimulationDirective $directive = null,
        public readonly array $metadata = [],
    ) {
        SensitiveDataGuard::assertClean($this->metadata, 'operation request');
    }
}
