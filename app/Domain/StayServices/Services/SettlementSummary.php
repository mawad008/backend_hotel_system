<?php

namespace App\Domain\StayServices\Services;

use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;

/**
 * The guest's full financial settlement, readable at any point of the stay
 * (before checkout as a projection, after it as the final account) — what
 * staff review at the desk: accommodation, services, service fee, tax,
 * loyalty discount, what was paid, and what happens to the deposit hold.
 *
 * Why a projection: accommodation, service fee and tax are only POSTED to
 * the folio inside the checkout transaction, so before checkout the folio's
 * own `charges_total` holds the service orders alone. This read model takes
 * those amounts from the reservation's snapshots instead (the exact figures
 * checkout will post), so the total is complete at every stage and equals
 * the folio's `charges_total` once checkout has posted them.
 *
 * Deposit: the booking deposit is a card HOLD (an authorization, never
 * counted as paid). At checkout the hold is applied to the bill — the single
 * final settlement collects the balance through it — and any part of the
 * hold the bill does not use is released to the guest, never captured.
 *
 * Pure: reads only the Folio's already-loaded reservation, charges and
 * payment — no queries (the folio ledger renders one per row).
 */
final class SettlementSummary
{
    /** The hold is still open: the deposit is reserved but not yet collected. */
    private const HOLD_OPEN_STATUSES = [
        Payment::STATUS_HOLD_ACTIVE,
        Payment::STATUS_CAPTURE_REQUESTED,
        Payment::STATUS_CAPTURE_FAILED,
        Payment::STATUS_FINAL_SETTLEMENT_REQUESTED,
        Payment::STATUS_SETTLEMENT_FAILED,
    ];

    /** The hold was collected toward the bill. */
    private const HOLD_APPLIED_STATUSES = [
        Payment::STATUS_CAPTURED,
        Payment::STATUS_SETTLED,
    ];

    /** The hold was released / refunded to the guest (booking cancelled, expired, refunded). */
    private const HOLD_RELEASED_STATUSES = [
        Payment::STATUS_CANCELLED,
        Payment::STATUS_EXPIRED,
        Payment::STATUS_REFUND_REQUESTED,
        Payment::STATUS_REFUNDED,
        Payment::STATUS_REFUND_FAILED,
    ];

    public const DEPOSIT_NONE = 'none';

    public const DEPOSIT_HELD = 'held';

    public const DEPOSIT_APPLIED = 'applied';

    public const DEPOSIT_RELEASED = 'released';

    /**
     * @return array<string, mixed>
     */
    public static function of(Folio $folio): array
    {
        $reservation = $folio->reservation;
        $owed = $folio->charges->filter(fn (FolioCharge $c) => in_array($c->status, FolioCharge::OWED_STATUSES, true));
        $sum = fn (string $source) => $owed->where('source_type', $source)
            ->reduce(fn (string $carry, FolioCharge $c) => bcadd($carry, (string) $c->total_amount, 2), '0.00');

        // Accommodation = the current stay price (extensions included; cut to
        // the nights stayed once an early departure is recorded).
        $accommodation = bcadd((string) ($reservation->price_snapshot ?? '0'), '0', 2);
        $services = $sum(FolioCharge::SOURCE_SERVICE_ORDER);
        $serviceFee = bcadd((string) ($reservation->service_fee_amount ?? '0'), '0', 2);
        $tax = $reservation->taxAmount();
        // Stored as a negative line; reported as a positive discount.
        $loyaltyDiscount = bcmul($sum(FolioCharge::SOURCE_LOYALTY_REDEMPTION), '-1', 2);

        $total = self::totalFor($reservation, bcsub($services, $loyaltyDiscount, 2));
        $paid = bcadd($folio->paymentsTotal, '0', 2);
        $remaining = self::max0(bcsub($total, $paid, 2));

        $payment = $folio->payment;
        $deposit = $payment === null ? '0.00' : bcadd((string) $payment->amount, '0', 2);
        $status = $payment?->status;

        $depositState = self::DEPOSIT_NONE;
        $depositApplied = '0.00';
        $depositReleased = '0.00';
        $balanceDue = $remaining;

        if (bccomp($deposit, '0.00', 2) === 1 && $status !== null) {
            if (in_array($status, self::HOLD_OPEN_STATUSES, true)) {
                // Not collected yet: the hold covers the balance first, the rest is released.
                $depositState = self::DEPOSIT_HELD;
                $available = self::max0(bcsub($deposit, $paid, 2));
                $depositApplied = self::min($available, $remaining);
                $depositReleased = bcsub($available, $depositApplied, 2);
                $balanceDue = bcsub($remaining, $depositApplied, 2);
            } elseif (in_array($status, self::HOLD_APPLIED_STATUSES, true)) {
                // Collected: it is already inside `paid`; any unused part was never captured.
                $depositState = self::DEPOSIT_APPLIED;
                $depositApplied = self::min($deposit, $paid);
                $depositReleased = self::max0(bcsub($deposit, $paid, 2));
            } elseif (in_array($status, self::HOLD_RELEASED_STATUSES, true)) {
                $depositState = self::DEPOSIT_RELEASED;
                $depositReleased = $deposit;
            }
        }

        return [
            'accommodation' => $accommodation,
            'services' => $services,
            'service_fee' => $serviceFee,
            'tax_rate' => $reservation->tax_rate ?? '0.00',
            'tax' => $tax,
            'loyalty_discount' => $loyaltyDiscount,
            'total' => $total,
            'paid' => $paid,
            'deposit' => [
                'amount' => $deposit,
                'state' => $depositState,
                'payment_status' => $status,
                'applied' => $depositApplied,
                'released' => $depositReleased,
            ],
            // What the guest still has to pay beyond what is paid and the deposit hold.
            'balance_due' => $balanceDue,
            // Paid more than the final bill (e.g. a captured deposit above it).
            'credit' => self::max0(bcsub($paid, $total, 2)),
            // false = a projection: the guest has not checked out yet.
            'is_final' => in_array($reservation->status, [Reservation::STATUS_CHECKED_OUT, Reservation::STATUS_INVOICED], true),
        ];
    }

    /**
     * The guest's full bill: accommodation + service fee + tax from the
     * reservation's snapshots, plus the posted service-order and loyalty
     * lines ($postedAdjustments = their signed sum). The folio ledger's
     * "outstanding only" filter uses this too, so both always agree.
     */
    public static function totalFor(Reservation $reservation, string $postedAdjustments): string
    {
        $accommodation = bcadd((string) ($reservation->price_snapshot ?? '0'), '0', 2);
        $serviceFee = bcadd((string) ($reservation->service_fee_amount ?? '0'), '0', 2);

        return bcadd(bcadd(bcadd($accommodation, $serviceFee, 2), $reservation->taxAmount(), 2), $postedAdjustments, 2);
    }

    private static function max0(string $amount): string
    {
        return bccomp($amount, '0.00', 2) === 1 ? $amount : '0.00';
    }

    private static function min(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? $a : $b;
    }
}
