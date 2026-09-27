<?php

namespace App\Domain\IdentityVerification\Repositories;

use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationDecisionRepositoryInterface;

class EloquentIdentityVerificationDecisionRepository implements IdentityVerificationDecisionRepositoryInterface
{
    public function create(array $data): IdentityVerificationDecision
    {
        return IdentityVerificationDecision::create($data)->refresh();
    }

    public function latestForSession(int $sessionId): ?IdentityVerificationDecision
    {
        return IdentityVerificationDecision::query()
            ->where('session_id', $sessionId)
            ->orderByDesc('id')
            ->first();
    }
}
