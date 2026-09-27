<?php

namespace App\Domain\DigitalAccess\Provider\Data;

use App\Domain\DigitalAccess\Provider\AccessResultStatus;

/**
 * Phase 7 — the normalized outcome of a single provider credential
 * operation (issue / revoke).
 *
 * This is all the provider returns. It creates and updates nothing: the
 * workflow layer decides what AccessGrant status / Reservation transition,
 * if any, follows from it.
 *
 * `credential` is the minted app-delivered PIN — present ONLY on a
 * successful issue(), null otherwise. It is the ONE piece of secret data
 * the provider produces; the workflow persists it to the encrypted
 * AccessGrant::$credential column and it never enters `context`, an audit
 * row, or a log line. `providerReference` is deterministic. Neither
 * `message` nor `context` ever contains the credential, a secret, or a raw
 * provider payload.
 */
final class AccessResult
{
    /**
     * @param  array<string, scalar|null>  $context  echoed, sanitized request metadata — never the credential
     */
    public function __construct(
        public readonly AccessResultStatus $status,
        public readonly string $providerReference,
        public readonly ?string $credential,
        public readonly string $providerCode,
        public readonly string $message,
        public readonly array $context = [],
    ) {}

    public function isFailure(): bool
    {
        return $this->status === AccessResultStatus::Failed;
    }

    /**
     * A plain, safe array representation for a later step to fold into an
     * AccessGrant's metadata. Deliberately omits the credential.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'status' => $this->status->value,
            'provider_reference' => $this->providerReference,
            'provider_code' => $this->providerCode,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
