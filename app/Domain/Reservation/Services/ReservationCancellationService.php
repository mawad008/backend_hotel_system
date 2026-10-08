<?php

namespace App\Domain\Reservation\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Loyalty\Services\LoyaltyService;
use App\Domain\Payment\Exceptions\PaymentRefundFailedException;
use App\Domain\Payment\Services\PaymentWorkflowService;
use App\Domain\Reservation\Exceptions\ReservationCancellationNotAllowedException;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The approved cancellation policy (2026-09-26), owned by the backend:
 *
 * - a refundable room/rate can be cancelled free, with a full refund,
 *   within `guest_booking.free_cancellation_hours` (24) of booking, or until
 *   the hotel's check-in time if that comes first;
 * - after that, or for a non-refundable rate, the guest cannot cancel;
 * - the policy is snapshotted on the reservation at booking time
 *   (`is_refundable`, `free_cancellation_until`), so later hotel/room
 *   changes never affect existing reservations.
 *
 * The refund is the release of the deposit hold through the payment
 * gateway; a failed release leaves the reservation active.
 */
class ReservationCancellationService
{
    /** Statuses a cancellation may start from (the stay has not begun). */
    private const CANCELLABLE_STATUSES = [
        Reservation::STATUS_PENDING,
        Reservation::STATUS_DEPOSIT_HELD,
        Reservation::STATUS_VERIFIED,
    ];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReservationRepositoryInterface $repository,
        private readonly PaymentWorkflowService $payments,
        private readonly AuditLogger $auditLogger,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * The snapshot to store on a new reservation.
     *
     * @return array{is_refundable: bool, free_cancellation_until: ?CarbonImmutable}
     */
    public static function snapshotFor(RoomType $roomType, Hotel $hotel, string $checkIn, ?CarbonInterface $bookedAt = null): array
    {
        $refundable = (bool) $roomType->refundable;

        if (! $refundable) {
            return ['is_refundable' => false, 'free_cancellation_until' => null];
        }

        $bookedAt = CarbonImmutable::instance($bookedAt ?? now());
        $window = $bookedAt->addHours((int) config('guest_booking.free_cancellation_hours', 24));

        return [
            'is_refundable' => true,
            'free_cancellation_until' => $window->min(self::checkInMoment($hotel, $checkIn)),
        ];
    }

    /** The hotel's check-in time on the arrival day, in UTC. */
    public static function checkInMoment(Hotel $hotel, string $checkIn): CarbonImmutable
    {
        $time = $hotel->check_in_time ? substr((string) $hotel->check_in_time, 0, 5) : '00:00';

        return CarbonImmutable::parse(substr($checkIn, 0, 10).' '.$time, $hotel->timezone ?: config('app.timezone'))->utc();
    }

    public function evaluate(Reservation $reservation, ?CarbonInterface $now = null): CancellationDecision
    {
        $now ??= now();
        $refundable = (bool) $reservation->is_refundable;
        $freeUntil = $reservation->free_cancellation_until;

        if (! in_array($reservation->status, self::CANCELLABLE_STATUSES, true)) {
            return new CancellationDecision(false, false, $refundable, $freeUntil, CancellationDecision::REASON_STATUS);
        }

        if (! $refundable) {
            return new CancellationDecision(false, false, false, null, CancellationDecision::REASON_NON_REFUNDABLE);
        }

        if ($freeUntil === null || $now->greaterThan($freeUntil)) {
            return new CancellationDecision(false, false, true, $freeUntil, CancellationDecision::REASON_WINDOW_CLOSED);
        }

        return new CancellationDecision(true, true, true, $freeUntil, null);
    }

    /**
     * Guest cancellation — only what the policy allows, always with the full
     * refund (hold released) first.
     *
     * @throws ReservationCancellationNotAllowedException
     */
    public function cancelByGuest(Reservation $reservation, ?string $reason = null): Reservation
    {
        return $this->cancel($reservation, $reason, null, 'guest', function (Reservation $locked): CancellationDecision {
            $decision = $this->evaluate($locked);

            if (! $decision->allowed) {
                throw new ReservationCancellationNotAllowedException((string) $decision->reason);
            }

            return $decision;
        });
    }

    /**
     * Staff may cancel any not-yet-started reservation; the policy only
     * decides whether the deposit is refunded (released). After the window
     * the hold stays in place for the hotel to settle.
     */
    public function cancelByStaff(Reservation $reservation, User $actor, ?string $reason = null): Reservation
    {
        return $this->cancel($reservation, $reason, $actor, 'staff', fn (Reservation $locked): CancellationDecision => $this->evaluate($locked));
    }

    /**
     * The booking was never finished within the completion window
     * (ReservationExpiryService). The reservation is cancelled FIRST — the
     * room is free and nothing can move it on — then the deposit hold is
     * released in full (the guest never got a completed booking). A failed
     * release leaves the hold on the cancelled reservation, and the expiry
     * sweep retries it ({@see releaseOrphanHold()}).
     */
    public function cancelAbandoned(Reservation $reservation, string $reason): Reservation
    {
        $cancelled = $this->transitionToCancelled($reservation, $reason, null, 'system_expired', function (Reservation $locked): CancellationDecision {
            if (! in_array($locked->status, [Reservation::STATUS_PENDING, Reservation::STATUS_DEPOSIT_HELD], true)) {
                throw new ReservationCancellationNotAllowedException(CancellationDecision::REASON_STATUS);
            }

            return new CancellationDecision(true, true, (bool) $locked->is_refundable, $locked->free_cancellation_until, null);
        });

        $this->releaseOrphanHold($cancelled);

        return $cancelled;
    }

    /**
     * Releases an active deposit hold left on a CANCELLED reservation (a
     * failed release, or a provider hold that succeeded after the booking
     * was already cancelled). Returns false when the provider refused —
     * the next sweep tries again.
     */
    public function releaseOrphanHold(Reservation $reservation): bool
    {
        try {
            $this->payments->releaseHold($reservation, null, fn (Reservation $locked): bool => $locked->status === Reservation::STATUS_CANCELLED);
        } catch (PaymentRefundFailedException) {
            return false;
        }

        return true;
    }

    /**
     * Race-safe guest / staff cancellation. $decide runs on the LOCKED
     * reservation (and throws to refuse), so the policy check and the
     * writes see the same row:
     *
     * 1. with a full refund the deposit release is opened under the
     *    reservation lock — its pending CANCEL_HOLD fences check-in — and
     *    the provider is called outside any transaction; a failed release
     *    throws and leaves the reservation active (approved behaviour);
     * 2. the reservation moves to CANCELLED under the lock. Meanwhile it can
     *    only have moved DEPOSIT_HELD -> VERIFIED, which is still cancellable.
     *
     * @param  callable(Reservation): CancellationDecision  $decide
     */
    private function cancel(Reservation $reservation, ?string $reason, ?User $actor, string $channel, callable $decide): Reservation
    {
        $decision = null;

        $released = $this->payments->releaseHold($reservation, $actor, function (Reservation $locked) use ($decide, &$decision): bool {
            $decision = $this->decideCancellable($locked, $decide);

            return $decision->fullRefund;
        });

        return $this->transitionToCancelled($reservation, $reason, $actor, $channel, function (Reservation $locked) use (&$decision, $decide): CancellationDecision {
            if ($decision === null) {
                return $this->decideCancellable($locked, $decide);
            }

            if (! in_array($locked->status, self::CANCELLABLE_STATUSES, true)) {
                throw new ReservationCancellationNotAllowedException(CancellationDecision::REASON_STATUS);
            }

            return $decision;
        }, $released !== null ? 'deposit_released' : null);
    }

    /** @param  callable(Reservation): CancellationDecision  $decide */
    private function decideCancellable(Reservation $locked, callable $decide): CancellationDecision
    {
        if (! in_array($locked->status, self::CANCELLABLE_STATUSES, true)) {
            throw new ReservationCancellationNotAllowedException(CancellationDecision::REASON_STATUS);
        }

        return $decide($locked);
    }

    /** @param  callable(Reservation): CancellationDecision  $decide */
    private function transitionToCancelled(Reservation $reservation, ?string $reason, ?User $actor, string $channel, callable $decide, ?string $refund = null): Reservation
    {
        $decision = null;

        $cancelled = $this->reservations->transitionTo(
            $reservation,
            Reservation::STATUS_CANCELLED,
            $actor,
            guard: function (Reservation $locked) use ($decide, &$decision): void {
                $decision = $decide($locked);
            },
        );

        $cancelled = $this->repository->update($cancelled, [
            'cancelled_at' => now(),
            'cancellation_reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null,
        ]);

        // Points redeemed on a cancelled booking go back to the guest.
        $this->loyalty->reverseRedemptionForReservation($cancelled, $actor);

        $this->auditLogger->record(
            $actor,
            'reservation.cancelled',
            $cancelled,
            after: [
                'channel' => $channel,
                'policy' => $decision->toArray(),
                'refund' => $refund ?? ($channel === 'system_expired' ? 'release_pending' : ($decision->fullRefund ? 'nothing_held' : 'none')),
            ],
            hotelId: $cancelled->hotel_id,
        );

        return $cancelled;
    }
}
