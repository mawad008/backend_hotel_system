<?php

namespace App\Domain\GuestAccess\Repositories;

use App\Domain\GuestAccess\Models\GuestOtpChallenge;
use App\Domain\GuestAccess\Repositories\Contracts\GuestOtpChallengeRepositoryInterface;

class EloquentGuestOtpChallengeRepository implements GuestOtpChallengeRepositoryInterface
{
    public function create(array $data): GuestOtpChallenge
    {
        return GuestOtpChallenge::create($data);
    }

    public function findActive(string $publicId, string $phone): ?GuestOtpChallenge
    {
        return GuestOtpChallenge::query()
            ->where('public_id', $publicId)
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->first();
    }

    public function latestForPhone(string $phone): ?GuestOtpChallenge
    {
        return GuestOtpChallenge::query()
            ->where('phone', $phone)
            ->latest('id')
            ->first();
    }

    public function update(GuestOtpChallenge $challenge, array $data): GuestOtpChallenge
    {
        $challenge->update($data);

        return $challenge->refresh();
    }

    public function consumeActiveForPhone(string $phone): void
    {
        GuestOtpChallenge::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
