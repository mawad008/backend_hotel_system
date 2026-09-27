<?php

namespace App\Domain\Notification\Provider;

use App\Domain\Notification\Provider\Exceptions\UnsupportedNotificationSimulationDirectiveException;

/**
 * Phase 11 — the deterministic directive a caller (or the dummy provider
 * configuration) supplies to steer a delivery to a known outcome (Phase 0
 * §15/§18: "every dummy adapter scenario is deterministic / test-selectable
 * — never randomized").
 *
 * §15 names the simulated states as "event logged internally; no external
 * call" plus a failure branch for testing. That is exactly two directives:
 *
 *   - deliver : the provider accepted the message
 *   - fail    : the provider could not deliver it
 *
 * This is an application-layer value, never raw client input. It is exposed
 * to a test only through the internal dispatch API, never an HTTP field.
 */
enum SimulationDirective: string
{
    case Deliver = 'deliver';

    case Fail = 'fail';

    public const DEFAULT = self::Deliver;

    /**
     * Resolve a directive from a configuration string, failing loudly on an
     * unknown value rather than silently defaulting.
     *
     * @throws UnsupportedNotificationSimulationDirectiveException
     */
    public static function fromConfig(?string $value): self
    {
        $value ??= self::DEFAULT->value;

        return self::tryFrom($value)
            ?? throw new UnsupportedNotificationSimulationDirectiveException($value);
    }
}
