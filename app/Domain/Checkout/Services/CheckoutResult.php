<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Models\Invoice;
use App\Domain\Payment\Models\Payment;

/**
 * Phase 9 — the outcome of a checkout attempt, handed back to the
 * controller. Carries only resolved domain models; the HTTP mapping and the
 * safe representation live in CheckoutController / CheckoutResource.
 */
final class CheckoutResult
{
    public function __construct(
        public readonly Checkout $checkout,
        public readonly ?Invoice $invoice,
        public readonly ?Payment $payment,
    ) {}

    public function isComplete(): bool
    {
        return $this->checkout->status === Checkout::STATUS_COMPLETED;
    }

    public function isSettlementPending(): bool
    {
        return $this->checkout->status === Checkout::STATUS_AWAITING_SETTLEMENT;
    }

    public function isSettlementFailed(): bool
    {
        return $this->checkout->status === Checkout::STATUS_SETTLEMENT_FAILED;
    }
}
