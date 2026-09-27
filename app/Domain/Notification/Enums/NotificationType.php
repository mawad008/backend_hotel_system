<?php

namespace App\Domain\Notification\Enums;

use App\Domain\Reservation\Models\Reservation;

/**
 * Phase 11 — the business event that produced a notification.
 *
 * Every value maps 1:1 to an already-approved Reservation state transition
 * (Phase 0 §8) that is a milestone in the approved guest journey
 * (R20: Browse → Book → Deposit → Verify → Digital Check-in → Stay → Auto
 * Checkout → E-Invoice) or the approved payment-failure edge case (R33). No
 * business event is invented here — a transition with no entry below simply
 * produces no notification.
 */
enum NotificationType: string
{
    /** PENDING → DEPOSIT_HELD — the deposit hold succeeded (R20 "Deposit", R26-R31). */
    case ReservationDepositHeld = 'reservation_deposit_held';

    /** DEPOSIT_HELD → VERIFIED — identity verification passed (R20 "Verify", R21-R25). */
    case IdentityVerified = 'identity_verified';

    /** VERIFIED → CHECKED_IN — digital check-in complete, access issued (R20 "Digital Check-in", §11). */
    case ReservationCheckedIn = 'reservation_checked_in';

    /** CHECKED_OUT → INVOICED — the e-invoice is available (R20 "E-Invoice", R14-R19). */
    case ReservationInvoiced = 'reservation_invoiced';

    /** any → CANCELLED — the booking was cancelled (R33 payment-fail-after-verify edge case). */
    case ReservationCancelled = 'reservation_cancelled';

    /**
     * The notification type for a Reservation status transition, or null when
     * the transition is not an approved notification milestone.
     */
    public static function forReservationTransition(string $from, string $to): ?self
    {
        return match (true) {
            $to === Reservation::STATUS_CANCELLED => self::ReservationCancelled,
            $to === Reservation::STATUS_DEPOSIT_HELD => self::ReservationDepositHeld,
            $from === Reservation::STATUS_DEPOSIT_HELD && $to === Reservation::STATUS_VERIFIED => self::IdentityVerified,
            $from === Reservation::STATUS_VERIFIED && $to === Reservation::STATUS_CHECKED_IN => self::ReservationCheckedIn,
            $from === Reservation::STATUS_CHECKED_OUT && $to === Reservation::STATUS_INVOICED => self::ReservationInvoiced,
            default => null,
        };
    }

    /**
     * The localization key namespace for this type's rendered message.
     */
    public function translationKey(): string
    {
        return 'notifications.'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
