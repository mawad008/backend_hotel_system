<?php

namespace App\Domain\DigitalAccess\Repositories\Contracts;

use App\Domain\DigitalAccess\Models\AccessGrant;

interface AccessGrantRepositoryInterface
{
    public function find(int $id): ?AccessGrant;

    /**
     * The single grant belonging to a Reservation (approved 1:1
     * relationship), or null if none has been issued yet.
     */
    public function findByReservation(int $reservationId): ?AccessGrant;

    /**
     * `find()` under a `SELECT ... FOR UPDATE` row lock. Call only from
     * within an active DB::transaction().
     */
    public function findForUpdate(int $id): ?AccessGrant;

    /**
     * `findByReservation()` under a row lock — so a concurrent check-in
     * cannot both discover "no grant" and both insert. Call only from
     * within an active DB::transaction().
     */
    public function findByReservationForUpdate(int $reservationId): ?AccessGrant;

    public function findByIdempotencyKey(string $key): ?AccessGrant;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AccessGrant;

    /**
     * Pure persistence — the caller owns every workflow decision, including
     * the DigitalAccessStateMachine guard.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AccessGrant $grant, array $data): AccessGrant;
}
