<?php

namespace App\Domain\Payment\Gateway;

use App\Domain\Payment\Gateway\Exceptions\UnsupportedSimulationDirectiveException;

/**
 * Phase 5B — the deterministic simulation directive a caller (or the dummy
 * configuration) supplies to steer a provider operation to a known outcome
 * (Phase 0 §9/§15: "every dummy adapter scenario is deterministic /
 * test-selectable — never randomized").
 *
 * These are the only simulated provider outcomes the approved planning
 * lists (Phase 0 §15: "initiated, pending, success, failure, cancelled,
 * expired"). `initiated` is a request-side stage, not an outcome, so it is
 * not represented here.
 *
 * This is an application-layer value, never raw client input: a future
 * production API MUST validate/authorize before letting a request pick a
 * directive (Phase 5B instructions §6).
 */
enum SimulationDirective: string
{
    case Success = 'success';

    case Pending = 'pending';

    case Failure = 'failure';

    case Cancelled = 'cancelled';

    case Expired = 'expired';

    /**
     * The directive used when a caller does not supply one — deterministic
     * success where the operation supports success (Phase 5B instructions
     * §6).
     */
    public const DEFAULT = self::Success;

    /**
     * Resolve a directive from a configuration string, failing loudly on an
     * unknown value rather than silently defaulting (Phase 5B §16 — "fail
     * clearly and safely").
     *
     * @throws UnsupportedSimulationDirectiveException
     */
    public static function fromConfig(?string $value): self
    {
        $value ??= self::DEFAULT->value;

        return self::tryFrom($value)
            ?? throw new UnsupportedSimulationDirectiveException($value);
    }
}
