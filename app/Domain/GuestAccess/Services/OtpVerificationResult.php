<?php

namespace App\Domain\GuestAccess\Services;

use App\Domain\Reservation\Models\Guest;

/**
 * The outcome of a verify call. Wrong-code and lock-out are ordinary flow
 * outcomes (returned, not thrown) — the HTTP layer maps every case to 200.
 */
final class OtpVerificationResult
{
    public const OUTCOME_AUTHENTICATED = 'authenticated';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_LOCKED_OUT = 'locked_out';

    private function __construct(
        public readonly string $outcome,
        public readonly ?Guest $guest = null,
        public readonly ?string $token = null,
        public readonly int $attemptsRemaining = 0,
    ) {}

    public static function authenticated(Guest $guest, string $token): self
    {
        return new self(self::OUTCOME_AUTHENTICATED, guest: $guest, token: $token);
    }

    public static function rejected(int $attemptsRemaining): self
    {
        return new self(self::OUTCOME_REJECTED, attemptsRemaining: $attemptsRemaining);
    }

    public static function lockedOut(): self
    {
        return new self(self::OUTCOME_LOCKED_OUT);
    }
}
