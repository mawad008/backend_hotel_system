<?php

namespace App\Domain\Reservation\Services;

use Carbon\CarbonInterface;

/**
 * The server's answer to "can this reservation be cancelled right now, and
 * is it refunded?" — what the guest app and the dashboard display.
 */
final class CancellationDecision
{
    public const REASON_STATUS = 'status_not_cancellable';

    public const REASON_NON_REFUNDABLE = 'non_refundable_rate';

    public const REASON_WINDOW_CLOSED = 'free_cancellation_window_closed';

    public function __construct(
        public readonly bool $allowed,
        public readonly bool $fullRefund,
        public readonly bool $refundable,
        public readonly ?CarbonInterface $freeUntil,
        public readonly ?string $reason,
    ) {}

    /** @return array{allowed: bool, refund: string, refundable: bool, free_until: ?string, reason: ?string} */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'refund' => $this->fullRefund ? 'full' : 'none',
            'refundable' => $this->refundable,
            'free_until' => $this->freeUntil?->toIso8601String(),
            'reason' => $this->reason,
        ];
    }
}
