<?php

namespace App\Domain\Loyalty\Exceptions;

use RuntimeException;

/**
 * Thrown when a loyalty earn/redeem cannot proceed. `$reason` is a short,
 * fixed machine code — never a secret or an internal detail. The public
 * constructors below are the only reasons Phase 10 checks.
 */
class LoyaltyNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("This loyalty operation is not allowed ({$reason}).");
    }

    public static function programInactive(): self
    {
        return new self('loyalty_program_inactive');
    }

    public static function earnRateNotConfigured(): self
    {
        return new self('earn_rate_not_configured');
    }

    public static function redeemRateNotConfigured(): self
    {
        return new self('redeem_rate_not_configured');
    }

    public static function reservationNotCompleted(string $status): self
    {
        return new self("reservation_not_completed:{$status}");
    }

    public static function reservationNotRedeemable(string $status): self
    {
        return new self("reservation_not_redeemable:{$status}");
    }

    public static function nothingToEarn(): self
    {
        return new self('no_eligible_booking_value');
    }

    public static function alreadyRedeemed(): self
    {
        return new self('already_redeemed_against_this_booking');
    }

    /** More points than the program's per-booking maximum (0 = none). */
    public static function exceedsMaximum(int $maximum): self
    {
        return new self('exceeds_maximum_redemption');
    }

    public static function insufficientBalance(): self
    {
        return new self('insufficient_points_balance');
    }
}
