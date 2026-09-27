<?php

namespace App\Domain\DigitalAccess\Provider;

/**
 * Phase 7 — the normalized outcome of a provider credential operation, as
 * reported by the provider boundary.
 *
 * This is the provider-operation outcome only. It is NOT an AccessGrant
 * status (Phase 0 §11) and the provider never decides a grant status — the
 * workflow layer does.
 */
enum AccessResultStatus: string
{
    /** issue(): the credential was created and activated. */
    case Active = 'active';

    /** revoke(): the credential was revoked at the provider. */
    case Revoked = 'revoked';

    /** either operation: the provider could not complete it. */
    case Failed = 'failed';

    public static function forIssueDirective(SimulationDirective $directive): self
    {
        return match ($directive) {
            SimulationDirective::Success => self::Active,
            SimulationDirective::Failure => self::Failed,
        };
    }

    public static function forRevokeDirective(SimulationDirective $directive): self
    {
        return match ($directive) {
            SimulationDirective::Success => self::Revoked,
            SimulationDirective::Failure => self::Failed,
        };
    }
}
