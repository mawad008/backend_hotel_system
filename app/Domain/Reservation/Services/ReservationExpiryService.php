<?php

namespace App\Domain\Reservation\Services;

use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationSessionRepositoryInterface;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Reservation\Exceptions\InvalidReservationStatusTransitionException;
use App\Domain\Reservation\Exceptions\ReservationCancellationNotAllowedException;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Abandoned-booking expiry (approved 2026-10-08, default 15 minutes —
 * `guest_booking.completion_window_minutes`).
 *
 * A PENDING booking must be paid, and a DEPOSIT_HELD one verified, before
 * its `completion_deadline_at`. Past it the booking is cancelled by the
 * system, which frees the room, and its deposit hold is released. Never
 * expired while something legitimately is in progress: a deposit hold the
 * provider is still processing, or an identity check that is matching or
 * waiting for staff review.
 */
class ReservationExpiryService
{
    public const REASON_EXPIRED = 'completion_window_expired';

    public const REASON_SUPERSEDED = 'superseded_by_new_booking';

    private const BATCH = 200;

    /** Identity states that are waiting on the system or staff, not the guest. */
    private const IDENTITY_IN_PROGRESS = [
        IdentityVerificationSession::STATUS_MATCHING_IN_PROGRESS,
        IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
    ];

    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly PaymentRepositoryInterface $payments,
        private readonly IdentityVerificationSessionRepositoryInterface $identitySessions,
        private readonly ReservationCancellationService $cancellations,
    ) {}

    /**
     * One sweep: expire overdue bookings, then retry releasing deposit holds
     * left on cancelled reservations.
     *
     * @return array{expired: int, skipped: int, holds_released: int, holds_pending: int}
     */
    public function sweep(): array
    {
        $expired = 0;
        $skipped = 0;

        foreach ($this->reservations->pastCompletionDeadline(now(), self::BATCH) as $reservation) {
            if ($this->inProgress($reservation)) {
                $skipped++;

                continue;
            }

            $this->expire($reservation, self::REASON_EXPIRED) ? $expired++ : $skipped++;
        }

        $released = 0;
        $pending = 0;

        foreach ($this->reservations->cancelledWithActiveHold(self::BATCH) as $reservation) {
            $this->cancellations->releaseOrphanHold($reservation) ? $released++ : $pending++;
        }

        return ['expired' => $expired, 'skipped' => $skipped, 'holds_released' => $released, 'holds_pending' => $pending];
    }

    /**
     * A guest starting a new booking for the same room type and dates
     * abandons their own earlier unpaid attempt — it must not keep holding
     * the room the new booking needs. A paid (DEPOSIT_HELD) booking, or one
     * whose hold is still processing, is never touched.
     */
    public function supersedeUnpaidAttempts(int $guestId, int $roomTypeId, string $checkIn, string $checkOut, ?string $idempotencyKey): int
    {
        $count = 0;

        foreach ($this->reservations->pendingForGuestOverlapping($guestId, $roomTypeId, $checkIn, $checkOut) as $reservation) {
            // The same Confirm being retried replays, it never supersedes itself.
            if ($idempotencyKey !== null && $reservation->idempotency_key === $idempotencyKey) {
                continue;
            }

            $payment = $this->payments->findByReservation($reservation->id);

            if ($payment !== null && in_array($payment->status, [Payment::STATUS_HOLD_REQUESTED, Payment::STATUS_HOLD_ACTIVE], true)) {
                continue;
            }

            $count += $this->expire($reservation, self::REASON_SUPERSEDED) ? 1 : 0;
        }

        return $count;
    }

    private function inProgress(Reservation $reservation): bool
    {
        $payment = $this->payments->findByReservation($reservation->id);

        if ($payment !== null && $payment->status === Payment::STATUS_HOLD_REQUESTED) {
            return true;
        }

        $identity = $this->identitySessions->findByReservation($reservation->id);

        return $identity !== null && in_array($identity->status, self::IDENTITY_IN_PROGRESS, true);
    }

    private function expire(Reservation $reservation, string $reason): bool
    {
        try {
            $this->cancellations->cancelAbandoned($reservation, $reason);

            return true;
        } catch (ReservationCancellationNotAllowedException|InvalidReservationStatusTransitionException) {
            // It moved on (paid / verified / cancelled) since it was read.
            return false;
        } catch (Throwable $e) {
            Log::warning('reservation expiry failed', ['reservation_id' => $reservation->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
