<?php

namespace App\Domain\IdentityVerification\Provider\Data;

use App\Domain\IdentityVerification\Provider\MatchOutcome;

/**
 * Phase 6 — the normalized outcome of a single identity verification match.
 *
 * This is all the provider returns. It creates and updates nothing: the
 * workflow layer decides what session status / attempt row / reservation
 * transition follows from it, by comparing `score` against the configured
 * confidence thresholds.
 *
 * `providerReference` is deterministic — a pure function of the attempt
 * reference. Neither `message` nor `context` ever contains a secret, a
 * document number, or a raw provider payload.
 */
final class VerificationResult
{
    /**
     * @param  int|null  $score  provider-normalized confidence 0..100, or null for a hard provider error
     * @param  array<string, scalar|null>  $context  echoed, sanitized request metadata
     */
    public function __construct(
        public readonly MatchOutcome $outcome,
        public readonly ?int $score,
        public readonly string $providerReference,
        public readonly string $providerCode,
        public readonly string $message,
        public readonly array $context = [],
    ) {}

    public function isError(): bool
    {
        return $this->outcome === MatchOutcome::Error;
    }

    /**
     * A plain, safe array representation for a later step to fold into an
     * attempt's metadata. Contains no secret and no PII.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'score' => $this->score,
            'provider_reference' => $this->providerReference,
            'provider_code' => $this->providerCode,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
