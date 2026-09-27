<?php

namespace App\Domain\IdentityVerification\Repositories;

use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationSessionRepositoryInterface;

class EloquentIdentityVerificationSessionRepository implements IdentityVerificationSessionRepositoryInterface
{
    public function find(int $id): ?IdentityVerificationSession
    {
        return IdentityVerificationSession::query()->find($id);
    }

    public function findByReservation(int $reservationId): ?IdentityVerificationSession
    {
        return IdentityVerificationSession::query()->where('reservation_id', $reservationId)->first();
    }

    public function findForUpdate(int $id): ?IdentityVerificationSession
    {
        return IdentityVerificationSession::query()->lockForUpdate()->find($id);
    }

    public function findByReservationForUpdate(int $reservationId): ?IdentityVerificationSession
    {
        return IdentityVerificationSession::query()
            ->where('reservation_id', $reservationId)
            ->lockForUpdate()
            ->first();
    }

    public function create(array $data): IdentityVerificationSession
    {
        return IdentityVerificationSession::create($data)->refresh();
    }

    public function update(IdentityVerificationSession $session, array $data): IdentityVerificationSession
    {
        $session->update($data);

        return $session->refresh();
    }
}
