<?php

namespace App\Domain\IdentityVerification\Provider;

/**
 * Phase 6 — the normalized outcome of one identity verification match, as
 * reported by the provider boundary.
 *
 * This is the provider-attempt outcome only. It is NOT an Identity
 * Verification session status (Phase 0 §10) and the provider never decides a
 * session status — the workflow layer does, by comparing the score against
 * the configured confidence thresholds.
 */
enum MatchOutcome: string
{
    case HighMatch = 'high_match';

    case MediumMatch = 'medium_match';

    case LowMatch = 'low_match';

    case Error = 'error';

    /**
     * The deterministic outcome each simulation directive maps to.
     */
    public static function forDirective(SimulationDirective $directive): self
    {
        return match ($directive) {
            SimulationDirective::HighMatch => self::HighMatch,
            SimulationDirective::MediumMatch => self::MediumMatch,
            SimulationDirective::LowMatch => self::LowMatch,
            SimulationDirective::Error => self::Error,
        };
    }

    /**
     * Whether the provider produced a usable score (everything except a
     * hard provider error).
     */
    public function hasScore(): bool
    {
        return $this !== self::Error;
    }
}
