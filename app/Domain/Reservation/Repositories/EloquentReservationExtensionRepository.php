<?php

namespace App\Domain\Reservation\Repositories;

use App\Domain\Reservation\Models\ReservationExtension;
use App\Domain\Reservation\Repositories\Contracts\ReservationExtensionRepositoryInterface;

class EloquentReservationExtensionRepository implements ReservationExtensionRepositoryInterface
{
    public function find(int $id): ?ReservationExtension
    {
        return ReservationExtension::query()->find($id);
    }

    public function findByIdempotencyKey(string $key): ?ReservationExtension
    {
        return ReservationExtension::query()
            ->where('idempotency_key', $key)
            ->first();
    }

    public function create(array $data): ReservationExtension
    {
        return ReservationExtension::create($data)->refresh();
    }

    public function update(ReservationExtension $extension, array $data): ReservationExtension
    {
        $extension->update($data);

        return $extension->refresh();
    }
}
