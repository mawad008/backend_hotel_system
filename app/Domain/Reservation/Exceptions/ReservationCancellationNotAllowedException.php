<?php

namespace App\Domain\Reservation\Exceptions;

use RuntimeException;

/**
 * The approved cancellation policy refuses this cancellation (non-refundable
 * rate, window closed, or the stay already started). `$reason` is a fixed
 * machine code from CancellationDecision.
 */
class ReservationCancellationNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("This reservation can no longer be cancelled ({$reason}).");
    }
}
