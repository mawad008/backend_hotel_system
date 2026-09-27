<?php

namespace App\Http\Resources\V1;

use App\Domain\GuestAccess\Models\GuestOtpChallenge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GuestOtpChallenge
 */
class GuestOtpChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cooldown = (int) config('otp.resend_cooldown_seconds');
        $elapsed = (int) abs($this->last_sent_at->diffInSeconds(now()));

        return [
            'challenge_id' => $this->public_id,
            'phone' => $this->phone,
            'code_length' => (int) config('otp.code_length'),
            'attempts_remaining' => $this->attemptsRemaining(),
            'expires_in' => max(0, (int) now()->diffInSeconds($this->expires_at, false)),
            'resend_available_in' => max(0, $cooldown - $elapsed),
        ];
    }
}
