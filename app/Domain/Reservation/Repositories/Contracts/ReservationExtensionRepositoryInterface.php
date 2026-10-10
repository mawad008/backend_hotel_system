<?php

namespace App\Domain\Reservation\Repositories\Contracts;

use App\Domain\Reservation\Models\ReservationExtension;
use Illuminate\Database\Eloquent\Collection;

interface ReservationExtensionRepositoryInterface
{
    public function find(int $id): ?ReservationExtension;

    /**
     * Idempotent-replay lookup — the `Idempotency-Key` header value is
     * globally unique on this table, mirroring
     * `PaymentTransactionRepositoryInterface::findByIdempotencyKey()`.
     */
    public function findByIdempotencyKey(string $key): ?ReservationExtension;

    /**
     * A reservation's extensions in stay order (earliest segment first).
     *
     * @return Collection<int, ReservationExtension>
     */
    public function allForReservation(int $reservationId): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ReservationExtension;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ReservationExtension $extension, array $data): ReservationExtension;
}
