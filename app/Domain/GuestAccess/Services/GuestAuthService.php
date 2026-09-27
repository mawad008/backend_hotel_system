<?php

namespace App\Domain\GuestAccess\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\GuestAccess\Exceptions\OtpChallengeExpiredException;
use App\Domain\GuestAccess\Exceptions\OtpChallengeNotFoundException;
use App\Domain\GuestAccess\Exceptions\OtpResendCooldownException;
use App\Domain\GuestAccess\Models\GuestOtpChallenge;
use App\Domain\GuestAccess\Otp\Contracts\OtpSenderInterface;
use App\Domain\GuestAccess\Repositories\Contracts\GuestOtpChallengeRepositoryInterface;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Repositories\Contracts\GuestRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Slice 0 — the guest authentication business authority. Owns OTP issuance,
 * verification, guest account resolution (find-or-create by phone), token
 * issuance and profile completion. The HTTP layer is thin: validate, call,
 * shape the response.
 *
 * The acting identity is never a staff `User`, so audit rows are written with
 * a null actor and the Guest as the subject — mirroring the nullable
 * `reservations.created_by_staff_id` convention for guest-initiated actions.
 */
class GuestAuthService
{
    public function __construct(
        private readonly GuestOtpChallengeRepositoryInterface $challenges,
        private readonly GuestRepositoryInterface $guests,
        private readonly OtpSenderInterface $sender,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Issues a fresh challenge for $phone (any earlier active challenge for
     * the same phone is invalidated first, so only the newest code works).
     * Abuse is bounded by the `guest.otp.request` rate limiter.
     */
    public function requestOtp(string $phone): GuestOtpChallenge
    {
        $this->challenges->consumeActiveForPhone($phone);

        $code = $this->generateCode();

        $challenge = $this->challenges->create([
            'public_id' => (string) Str::uuid(),
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'max_attempts' => (int) config('otp.max_attempts'),
            'resend_count' => 0,
            'last_sent_at' => now(),
            'expires_at' => now()->addSeconds((int) config('otp.ttl_seconds')),
        ]);

        $this->sender->send($phone, $code);
        $this->auditLogger->record(null, 'guest.otp.requested', $challenge);

        return $challenge;
    }

    /**
     * Re-sends a code for an existing challenge, resetting its attempt
     * counter. Enforces the per-challenge cooldown and resend ceiling.
     */
    public function resendOtp(string $publicId, string $phone): GuestOtpChallenge
    {
        $challenge = $this->challenges->findActive($publicId, $phone);

        if (! $challenge) {
            throw new OtpChallengeNotFoundException;
        }

        if ($challenge->isExpired()) {
            throw new OtpChallengeExpiredException;
        }

        $cooldown = (int) config('otp.resend_cooldown_seconds');
        $elapsed = (int) abs($challenge->last_sent_at->diffInSeconds(now()));

        if ($elapsed < $cooldown) {
            throw new OtpResendCooldownException($cooldown - $elapsed);
        }

        if ($challenge->resend_count >= (int) config('otp.max_resends')) {
            throw new OtpResendCooldownException;
        }

        $code = $this->generateCode();

        $challenge = $this->challenges->update($challenge, [
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'resend_count' => $challenge->resend_count + 1,
            'last_sent_at' => now(),
            'expires_at' => now()->addSeconds((int) config('otp.ttl_seconds')),
        ]);

        $this->sender->send($phone, $code);
        $this->auditLogger->record(null, 'guest.otp.resent', $challenge);

        return $challenge;
    }

    /**
     * Verifies $code against the challenge. On success: consumes the
     * challenge, finds-or-creates the Guest by phone, marks the phone
     * verified, issues a `guest-api` Sanctum token.
     */
    public function verifyOtp(string $publicId, string $phone, string $code): OtpVerificationResult
    {
        $challenge = $this->challenges->findActive($publicId, $phone);

        if (! $challenge) {
            throw new OtpChallengeNotFoundException;
        }

        if ($challenge->isExpired()) {
            throw new OtpChallengeExpiredException;
        }

        if ($challenge->isLockedOut()) {
            return OtpVerificationResult::lockedOut();
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge = $this->challenges->update($challenge, ['attempts' => $challenge->attempts + 1]);

            if ($challenge->isLockedOut()) {
                $this->challenges->update($challenge, ['consumed_at' => now()]);
                $this->auditLogger->record(null, 'guest.otp.locked_out', $challenge);

                return OtpVerificationResult::lockedOut();
            }

            return OtpVerificationResult::rejected($challenge->attemptsRemaining());
        }

        $this->challenges->update($challenge, ['consumed_at' => now()]);

        $guest = $this->guests->findByPhone($phone);

        if (! $guest) {
            $guest = $this->guests->create([
                'phone' => $phone,
                'phone_verified_at' => now(),
            ]);
        } elseif ($guest->phone_verified_at === null) {
            $guest = $this->guests->update($guest, ['phone_verified_at' => now()]);
        }

        $token = $guest->createToken('guest-api')->plainTextToken;
        $this->auditLogger->record(null, 'guest.auth.login', $guest);

        return OtpVerificationResult::authenticated($guest, $token);
    }

    /**
     * Saves / updates the first-time guest's name + email. `rating`/`body`
     * style immutability does not apply — a guest may edit their own
     * profile freely.
     */
    public function completeProfile(Guest $guest, string $name, string $email): Guest
    {
        $updated = $this->guests->update($guest, [
            'name' => $name,
            'email' => $email,
            'profile_completed_at' => $guest->profile_completed_at ?? now(),
        ]);

        $this->auditLogger->record(null, 'guest.profile.completed', $updated);

        return $updated;
    }

    /**
     * Merge the guest's stay/communication preferences
     * (`PROFILE_Preferences`). Only the known keys are stored; omitted keys
     * keep their current value.
     *
     * @param  array<string, bool>  $preferences
     */
    public function updatePreferences(Guest $guest, array $preferences, ?string $identityRetention = null): Guest
    {
        $before = ['preferences' => $guest->resolvedPreferences(), 'identity_retention' => $guest->identity_retention];
        $merged = array_intersect_key($preferences, Guest::PREFERENCE_DEFAULTS) + $before['preferences'];

        $changes = ['preferences' => array_map('boolval', $merged)];
        if ($identityRetention !== null && in_array($identityRetention, Guest::IDENTITY_RETENTION_OPTIONS, true)) {
            $changes['identity_retention'] = $identityRetention;
        }

        $updated = $this->guests->update($guest, $changes);

        $this->auditLogger->record(null, 'guest.preferences.updated', $updated, before: $before, after: ['preferences' => $updated->resolvedPreferences(), 'identity_retention' => $updated->identity_retention]);

        return $updated;
    }

    /**
     * The guest asks for their personal data to be deleted
     * (`PROFILE_Privacy` "طلب حذف بياناتي"). Idempotent — the first request
     * time is kept. Staff act on it from the guest profile; the deletion
     * itself is a staff/legal process.
     */
    public function requestDataDeletion(Guest $guest): Guest
    {
        if ($guest->data_deletion_requested_at !== null) {
            return $guest;
        }

        $updated = $this->guests->update($guest, ['data_deletion_requested_at' => now()]);

        $this->auditLogger->record(null, 'guest.data_deletion.requested', $updated);

        return $updated;
    }

    public function logout(Guest $guest): void
    {
        $guest->currentAccessToken()?->delete();
        $this->auditLogger->record(null, 'guest.auth.logout', $guest);
    }

    private function generateCode(): string
    {
        $fixed = config('otp.fixed_code');

        if (filled($fixed)) {
            return (string) $fixed;
        }

        $length = (int) config('otp.code_length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
