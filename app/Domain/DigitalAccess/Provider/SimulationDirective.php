<?php

namespace App\Domain\DigitalAccess\Provider;

use App\Domain\DigitalAccess\Provider\Exceptions\UnsupportedDigitalAccessSimulationDirectiveException;

/**
 * Phase 7 — the deterministic directive a caller (or the dummy provider
 * configuration) supplies to steer a credential operation to a known
 * outcome (Phase 0 §15/§18: "every dummy adapter scenario is deterministic /
 * test-selectable — never randomized").
 *
 * §11 / §15 name the simulated credential lifecycle as "creation,
 * activation, validation, expiration, revocation, failure". Creation +
 * activation are the success path of issue(); revocation is the success path
 * of revoke(); expiration is application-driven (not a provider call);
 * validation is future (no endpoint this phase). That leaves exactly two
 * caller-selectable directives:
 *
 *   - success : issue -> an active credential; revoke -> revoked
 *   - failure : the provider could not complete the operation
 *
 * This is an application-layer value, never raw client input: the API layer
 * only exposes it through a guarded local/testing header.
 */
enum SimulationDirective: string
{
    case Success = 'success';

    case Failure = 'failure';

    /**
     * The directive used when a caller does not supply one.
     */
    public const DEFAULT = self::Success;

    /**
     * Resolve a directive from a configuration string, failing loudly on an
     * unknown value rather than silently defaulting.
     *
     * @throws UnsupportedDigitalAccessSimulationDirectiveException
     */
    public static function fromConfig(?string $value): self
    {
        $value ??= self::DEFAULT->value;

        return self::tryFrom($value)
            ?? throw new UnsupportedDigitalAccessSimulationDirectiveException($value);
    }
}
