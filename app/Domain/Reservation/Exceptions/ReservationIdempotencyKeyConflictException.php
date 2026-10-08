<?php

namespace App\Domain\Reservation\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when a guest replays a booking `Idempotency-Key` with a different
 * room type, dates or party — one key may only ever replay the exact same
 * booking (mirrors ReservationExtensionIdempotencyKeyConflictException).
 */
class ReservationIdempotencyKeyConflictException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(
            ErrorText::message('reservation_idempotency_conflict', ['reason' => $reason])
        );
    }
}
