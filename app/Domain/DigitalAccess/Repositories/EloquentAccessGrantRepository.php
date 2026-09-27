<?php

namespace App\Domain\DigitalAccess\Repositories;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Repositories\Contracts\AccessGrantRepositoryInterface;

class EloquentAccessGrantRepository implements AccessGrantRepositoryInterface
{
    public function find(int $id): ?AccessGrant
    {
        return AccessGrant::query()->find($id);
    }

    public function findByReservation(int $reservationId): ?AccessGrant
    {
        return AccessGrant::query()->where('reservation_id', $reservationId)->first();
    }

    public function findForUpdate(int $id): ?AccessGrant
    {
        return AccessGrant::query()->lockForUpdate()->find($id);
    }

    public function findByReservationForUpdate(int $reservationId): ?AccessGrant
    {
        return AccessGrant::query()
            ->where('reservation_id', $reservationId)
            ->lockForUpdate()
            ->first();
    }

    public function findByIdempotencyKey(string $key): ?AccessGrant
    {
        return AccessGrant::query()->where('idempotency_key', $key)->first();
    }

    public function create(array $data): AccessGrant
    {
        return AccessGrant::create($data)->refresh();
    }

    public function update(AccessGrant $grant, array $data): AccessGrant
    {
        $grant->update($data);

        return $grant->refresh();
    }
}
