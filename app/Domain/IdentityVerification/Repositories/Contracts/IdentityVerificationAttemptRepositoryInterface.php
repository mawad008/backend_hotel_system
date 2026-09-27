<?php

namespace App\Domain\IdentityVerification\Repositories\Contracts;

use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;

interface IdentityVerificationAttemptRepositoryInterface
{
    public function find(int $id): ?IdentityVerificationAttempt;

    /**
     * `find()` under a row lock. Call only from within an active
     * DB::transaction().
     */
    public function findForUpdate(int $id): ?IdentityVerificationAttempt;

    public function findByIdempotencyKey(string $key): ?IdentityVerificationAttempt;

    /**
     * The most recent attempt for a session, by attempt_number.
     */
    public function latestForSession(int $sessionId): ?IdentityVerificationAttempt;

    /**
     * `latestForSession()` under a row lock. Call only from within an
     * active DB::transaction().
     */
    public function latestForSessionForUpdate(int $sessionId): ?IdentityVerificationAttempt;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): IdentityVerificationAttempt;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(IdentityVerificationAttempt $attempt, array $data): IdentityVerificationAttempt;
}
