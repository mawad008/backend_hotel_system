<?php

namespace App\Domain\GuestAccess\Repositories\Contracts;

use App\Domain\GuestAccess\Models\GuestOtpChallenge;

interface GuestOtpChallengeRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): GuestOtpChallenge;

    /**
     * The active (not consumed) challenge for this public id + phone pair,
     * or null. Expiry is not filtered here — the service distinguishes
     * "expired" from "not found".
     */
    public function findActive(string $publicId, string $phone): ?GuestOtpChallenge;

    /**
     * The most recent challenge issued for a phone, consumed or not — used
     * for the request-level cooldown check.
     */
    public function latestForPhone(string $phone): ?GuestOtpChallenge;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GuestOtpChallenge $challenge, array $data): GuestOtpChallenge;

    /**
     * Marks every still-active challenge for a phone as consumed — called
     * before issuing a new one so only the newest code is ever valid.
     */
    public function consumeActiveForPhone(string $phone): void;
}
