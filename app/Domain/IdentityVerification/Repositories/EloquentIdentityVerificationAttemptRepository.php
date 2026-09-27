<?php

namespace App\Domain\IdentityVerification\Repositories;

use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationAttemptRepositoryInterface;

class EloquentIdentityVerificationAttemptRepository implements IdentityVerificationAttemptRepositoryInterface
{
    public function find(int $id): ?IdentityVerificationAttempt
    {
        return IdentityVerificationAttempt::query()->find($id);
    }

    public function findForUpdate(int $id): ?IdentityVerificationAttempt
    {
        return IdentityVerificationAttempt::query()->lockForUpdate()->find($id);
    }

    public function findByIdempotencyKey(string $key): ?IdentityVerificationAttempt
    {
        return IdentityVerificationAttempt::query()->where('idempotency_key', $key)->first();
    }

    public function latestForSession(int $sessionId): ?IdentityVerificationAttempt
    {
        return IdentityVerificationAttempt::query()
            ->where('session_id', $sessionId)
            ->orderByDesc('attempt_number')
            ->first();
    }

    public function latestForSessionForUpdate(int $sessionId): ?IdentityVerificationAttempt
    {
        return IdentityVerificationAttempt::query()
            ->where('session_id', $sessionId)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();
    }

    public function create(array $data): IdentityVerificationAttempt
    {
        return IdentityVerificationAttempt::create($data)->refresh();
    }

    public function update(IdentityVerificationAttempt $attempt, array $data): IdentityVerificationAttempt
    {
        $attempt->update($data);

        return $attempt->refresh();
    }
}
