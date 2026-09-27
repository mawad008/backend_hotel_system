<?php

namespace App\Domain\IdentityVerification\Repositories\Contracts;

use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;

interface IdentityVerificationDecisionRepositoryInterface
{
    /**
     * Append one immutable decision row.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): IdentityVerificationDecision;

    /**
     * The most recent decision for a session, or null if none yet.
     */
    public function latestForSession(int $sessionId): ?IdentityVerificationDecision;
}
