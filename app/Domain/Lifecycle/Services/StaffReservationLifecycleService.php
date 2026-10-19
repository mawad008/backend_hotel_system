<?php

namespace App\Domain\Lifecycle\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Services\IdentityVerificationService;
use App\Domain\Lifecycle\Exceptions\LifecycleStepIncompleteException;
use App\Domain\Payment\Services\PaymentWorkflowService;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationCancellationService;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\Reservation\StateMachine\ReservationStateMachine;

/**
 * Management override: the dashboard moves a reservation to its next status
 * by running the SAME domain flow the guest app runs for that step — never
 * by writing the status alone — so payments, identity, the digital key, the
 * folio, settlement, invoice, loyalty and notifications all follow:
 *
 *   → deposit_held          PaymentWorkflowService::holdDeposit (the app's deposit rule, via the gateway)
 *   → verified              IdentityVerificationService::overrideApprove (staff approval, no documents)
 *   → checked_in            DigitalAccessService::checkIn (reception channel; issues the key, then in_stay)
 *   → in_stay               ReservationService (only a stay stuck in checked_in — the app does this inside check-in)
 *   → checkout_in_progress,
 *     checked_out, invoiced CheckoutService::checkout (folio lines, final settlement, invoice)
 *   → checkout_blocked      ReservationService (a staff decision; the app has no flow for it)
 *   → cancelled             ReservationCancellationService::cancelByStaff (policy + deposit release)
 *
 * Every eligibility rule of the delegated flow still applies (e.g. check-in
 * needs an assigned room and an active deposit hold). Only the approved
 * transition table decides which targets are offered.
 *
 * Lives in its own domain so Reservation and Payment never depend on
 * Checkout (CheckoutArchitectureTest).
 */
class StaffReservationLifecycleService
{
    /** Happy-path order, to tell "reached (or passed) the target" from "stopped short". */
    private const ORDER = [
        Reservation::STATUS_PENDING,
        Reservation::STATUS_DEPOSIT_HELD,
        Reservation::STATUS_VERIFIED,
        Reservation::STATUS_CHECKED_IN,
        Reservation::STATUS_IN_STAY,
        Reservation::STATUS_CHECKOUT_IN_PROGRESS,
        Reservation::STATUS_CHECKED_OUT,
        Reservation::STATUS_INVOICED,
    ];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReservationCancellationService $cancellations,
        private readonly PaymentWorkflowService $payments,
        private readonly IdentityVerificationService $identity,
        private readonly DigitalAccessService $digitalAccess,
        private readonly CheckoutService $checkout,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @throws LifecycleStepIncompleteException when the flow ran but stopped before $targetStatus
     */
    public function advance(
        Reservation $reservation,
        string $targetStatus,
        User $actor,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): Reservation {
        $from = $reservation->status;

        ReservationStateMachine::assertCanTransition($from, $targetStatus);

        $incomplete = match ($targetStatus) {
            Reservation::STATUS_CANCELLED => $this->cancel($reservation, $actor, $reason),
            Reservation::STATUS_DEPOSIT_HELD => $this->holdDeposit($reservation, $actor, $idempotencyKey),
            Reservation::STATUS_VERIFIED => $this->verify($reservation, $actor, $reason),
            Reservation::STATUS_CHECKED_IN => $this->checkIn($reservation, $actor, $idempotencyKey),
            Reservation::STATUS_CHECKOUT_IN_PROGRESS,
            Reservation::STATUS_CHECKED_OUT,
            Reservation::STATUS_INVOICED => $this->checkOut($reservation, $actor, $idempotencyKey),
            // in_stay (from a stuck checked_in) and checkout_blocked have no
            // app flow of their own: the status change is the whole step.
            default => $this->plainTransition($reservation, $targetStatus, $actor),
        };

        $current = $reservation->fresh();

        $this->auditLogger->record(
            $actor,
            'reservation.staff_advanced',
            $current,
            after: array_filter([
                'from' => $from,
                'target' => $targetStatus,
                'status' => $current->status,
                'reason' => $reason,
                'incomplete' => $incomplete,
            ], fn ($v) => $v !== null),
            hotelId: $current->hotel_id,
        );

        if (! $this->reached($current->status, $targetStatus)) {
            throw new LifecycleStepIncompleteException($targetStatus, $current->status, $incomplete ?? 'not_reached');
        }

        return $current;
    }

    private function cancel(Reservation $reservation, User $actor, ?string $reason): ?string
    {
        $this->cancellations->cancelByStaff($reservation, $actor, $reason);

        return null;
    }

    private function holdDeposit(Reservation $reservation, User $actor, ?string $key): ?string
    {
        $payment = $this->payments->holdDeposit($reservation, $key, null, $actor);

        // null = a 0% hotel, confirmed without a hold.
        return $payment === null ? null : 'payment_'.$payment->status;
    }

    private function verify(Reservation $reservation, User $actor, ?string $reason): ?string
    {
        $this->identity->overrideApprove($reservation, $actor, $reason);

        return null;
    }

    private function checkIn(Reservation $reservation, User $actor, ?string $key): ?string
    {
        $grant = $this->digitalAccess->checkIn($reservation, null, $key, $actor);

        return $grant->status === AccessGrant::STATUS_ACTIVE ? null : 'access_'.$grant->status;
    }

    private function checkOut(Reservation $reservation, User $actor, ?string $key): ?string
    {
        $result = $this->checkout->checkout($reservation, $key, null, $actor);

        return $result->isComplete() ? null : 'checkout_'.$result->checkout->status;
    }

    private function plainTransition(Reservation $reservation, string $targetStatus, User $actor): ?string
    {
        $this->reservations->transitionTo($reservation, $targetStatus, $actor);

        return null;
    }

    /**
     * The flow may legitimately go past the target — check-in moves on to
     * in_stay and checkout to invoiced, exactly as in the app.
     */
    private function reached(string $current, string $target): bool
    {
        if ($current === $target) {
            return true;
        }

        $at = array_search($current, self::ORDER, true);
        $want = array_search($target, self::ORDER, true);

        return $at !== false && $want !== false && $at > $want;
    }
}
