<?php

namespace App\Domain\Payment\Gateway\Data;

use App\Domain\Payment\Gateway\GatewayOperation;
use App\Domain\Payment\Gateway\GatewayResultStatus;

/**
 * Phase 5B — the normalized outcome of a single provider operation
 * (initiateHold / cancelHold / capture / settle / verify).
 *
 * This is all the gateway returns. It creates and updates nothing: the
 * workflow layer (Phase 5C) decides what Payment status / PaymentTransaction
 * row / Reservation transition, if any, follows from it.
 *
 * `providerReference` is deterministic — a pure function of the operation
 * and the caller's seed reference. `providerCode` is a stable dummy
 * machine string (e.g. `dummy_hold_succeeded`). Neither `message` nor
 * `context` ever contains a secret, a signature, or raw provider input.
 */
final class GatewayResult
{
    /**
     * @param  array<string, scalar|null>  $context  echoed, sanitized request metadata
     */
    public function __construct(
        public readonly GatewayOperation $operation,
        public readonly GatewayResultStatus $status,
        public readonly string $providerReference,
        public readonly string $providerCode,
        public readonly string $message,
        public readonly array $context = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    /**
     * A plain, safe array representation for logging or for a later phase
     * to fold into a PaymentTransaction's metadata. Contains no secret.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'status' => $this->status->value,
            'provider_reference' => $this->providerReference,
            'provider_code' => $this->providerCode,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
