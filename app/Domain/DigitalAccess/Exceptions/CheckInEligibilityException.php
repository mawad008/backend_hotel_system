<?php

namespace App\Domain\DigitalAccess\Exceptions;

use RuntimeException;

/**
 * Thrown when a Reservation is in VERIFIED but a hard check-in precondition
 * from the approved baseline is not met (Phase 0 §8 "CHECKED_IN requires
 * both payment-confirmed and VERIFIED"; §11 "payment confirmed + verified +
 * correct time window").
 *
 * $reason is a short, fixed machine code — never a provider error, never a
 * secret. The public constructors below are the only reasons Phase 7 checks.
 */
class CheckInEligibilityException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Check-in is not currently eligible ({$reason}).");
    }

    public static function paymentNotConfirmed(): self
    {
        return new self('payment_not_confirmed');
    }

    public static function identityNotVerified(): self
    {
        return new self('identity_not_verified');
    }

    public static function outsideStayWindow(): self
    {
        return new self('outside_stay_window');
    }

    /** The hotel's check-in mode does not allow this channel (self / reception). */
    public static function channelNotAllowed(): self
    {
        return new self('check_in_channel_not_allowed');
    }

    /** Reception has not assigned the physical room yet. */
    public static function roomNotAssigned(): self
    {
        return new self('room_not_assigned');
    }
}
