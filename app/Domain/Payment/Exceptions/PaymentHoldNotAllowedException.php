<?php

namespace App\Domain\Payment\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when a payment hold is requested for a Reservation that is not in
 * a state that permits it (Phase 5C §6 — the Reservation must be PENDING).
 *
 * The message names only the two business facts involved — no internal
 * implementation detail — mirroring InvalidReservationStatusTransitionException.
 */
class PaymentHoldNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reservationStatus)
    {
        parent::__construct(
            ErrorText::message('payment_hold_not_allowed', ['status' => ErrorText::status($reservationStatus)])
        );
    }
}
