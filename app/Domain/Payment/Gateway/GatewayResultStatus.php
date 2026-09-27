<?php

namespace App\Domain\Payment\Gateway;

/**
 * Phase 5B — the normalized outcome of a provider operation, as reported by
 * the gateway boundary. Values match PaymentTransaction::STATUSES exactly
 * so a later phase can persist a transaction row from a result without a
 * mapping table.
 *
 * This is the provider-attempt outcome only. It is NOT a Payment status
 * (Phase 0 §9) and the gateway never decides a Payment status — that is the
 * workflow layer's job in Phase 5C.
 */
enum GatewayResultStatus: string
{
    case Pending = 'pending';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    case Cancelled = 'cancelled';

    case Expired = 'expired';

    /**
     * The deterministic outcome each simulation directive maps to. The
     * mapping is uniform across every operation — no per-operation special
     * cases — so a given (operation, directive) pair is always reproducible.
     */
    public static function forDirective(SimulationDirective $directive): self
    {
        return match ($directive) {
            SimulationDirective::Success => self::Succeeded,
            SimulationDirective::Pending => self::Pending,
            SimulationDirective::Failure => self::Failed,
            SimulationDirective::Cancelled => self::Cancelled,
            SimulationDirective::Expired => self::Expired,
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }
}
