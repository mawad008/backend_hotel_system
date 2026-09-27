<?php

namespace App\Domain\Loyalty\Exceptions;

use RuntimeException;

/**
 * Defensive guard: a points value that is not a positive integer reached
 * the service layer. The Form Request rejects this first; the service never
 * trusts that alone.
 */
class InvalidLoyaltyPointsException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'points_must_be_a_positive_integer')
    {
        parent::__construct("The points value is invalid ({$reason}).");
    }
}
