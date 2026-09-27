<?php

namespace App\Domain\IdentityVerification\Provider;

use App\Domain\IdentityVerification\Provider\Exceptions\UnsupportedIdentityVerificationSimulationDirectiveException;

/**
 * Phase 6 — the deterministic directive a caller (or the dummy provider
 * configuration) supplies to steer the identity match to a known outcome
 * (Phase 0 §15/§18: "every dummy adapter scenario is deterministic /
 * test-selectable — never randomized").
 *
 * These map one-to-one onto the branches the approved Identity Verification
 * state machine actually has (Phase 0 §10):
 *   - high_match   -> score in the HIGH band  -> AUTO_APPROVED
 *   - medium_match -> score in the MEDIUM band -> PENDING_MANUAL_REVIEW
 *   - low_match    -> score in the LOW band    -> PENDING_MANUAL_REVIEW
 *   - error        -> provider error           -> RETRY_ALLOWED / manual review
 *
 * "processing" (an async/pending provider state) is deliberately NOT
 * represented: the approved §16 endpoint map has no verification callback
 * route, so an async result could never resolve. That is future work.
 *
 * This is an application-layer value, never raw client input: the API layer
 * only exposes it through a guarded local/testing header.
 */
enum SimulationDirective: string
{
    case HighMatch = 'high_match';

    case MediumMatch = 'medium_match';

    case LowMatch = 'low_match';

    case Error = 'error';

    /**
     * The directive used when a caller does not supply one.
     */
    public const DEFAULT = self::HighMatch;

    /**
     * Resolve a directive from a configuration string, failing loudly on an
     * unknown value rather than silently defaulting.
     *
     * @throws UnsupportedIdentityVerificationSimulationDirectiveException
     */
    public static function fromConfig(?string $value): self
    {
        $value ??= self::DEFAULT->value;

        return self::tryFrom($value)
            ?? throw new UnsupportedIdentityVerificationSimulationDirectiveException($value);
    }
}
