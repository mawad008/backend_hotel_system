<?php

namespace App\Domain\GuestAccess\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One OTP challenge. The plaintext code lives nowhere — only `code_hash`.
 * `public_id` is the opaque handle the client echoes back as `challenge_id`.
 */
class GuestOtpChallenge extends Model
{
    protected $fillable = [
        'public_id',
        'phone',
        'code_hash',
        'attempts',
        'max_attempts',
        'resend_count',
        'last_sent_at',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'resend_count' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function attemptsRemaining(): int
    {
        return max(0, $this->max_attempts - $this->attempts);
    }

    public function isLockedOut(): bool
    {
        return $this->attemptsRemaining() <= 0;
    }
}
