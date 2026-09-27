<?php

namespace App\Domain\Payment\Gateway\Data;

use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Gateway\Support\SensitiveDataGuard;

/**
 * Phase 5B — the input to PaymentGatewayInterface::initiateHold.
 *
 * A plain application-layer value object: it carries no Eloquent model, no
 * repository, and no persistence concern. The workflow layer (Phase 5C)
 * builds one of these from a Payment/Reservation and is responsible for
 * persisting whatever the gateway returns.
 *
 * `intentReference` is an opaque, caller-chosen correlation string (for
 * example a Payment idempotency key). The dummy gateway uses it purely as a
 * deterministic seed for the provider reference it returns — same seed,
 * same reference, every time.
 */
final class GatewayHoldRequest
{
    /**
     * @param  array<string, scalar|null>  $metadata  safe, non-sensitive context only
     */
    public function __construct(
        public readonly string $intentReference,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?SimulationDirective $directive = null,
        public readonly array $metadata = [],
    ) {
        SensitiveDataGuard::assertClean($this->metadata, 'hold request');
    }
}
