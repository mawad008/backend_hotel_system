<?php

namespace App\Domain\IdentityVerification\Repositories\Contracts;

use App\Domain\IdentityVerification\Models\IdentityVerificationSession;

interface IdentityVerificationSessionRepositoryInterface
{
    public function find(int $id): ?IdentityVerificationSession;

    /**
     * The single session belonging to a Reservation (approved 1:1
     * relationship), or null if none has been started yet.
     */
    public function findByReservation(int $reservationId): ?IdentityVerificationSession;

    /**
     * `find()` under a `SELECT ... FOR UPDATE` row lock. Call only from
     * within an active DB::transaction().
     */
    public function findForUpdate(int $id): ?IdentityVerificationSession;

    /**
     * `findByReservation()` under a row lock — so a concurrent initiation
     * cannot both discover "no session" and both insert. Call only from
     * within an active DB::transaction().
     */
    public function findByReservationForUpdate(int $reservationId): ?IdentityVerificationSession;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): IdentityVerificationSession;

    /**
     * Pure persistence — the caller owns every workflow decision, including
     * the IdentityVerificationStateMachine guard.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(IdentityVerificationSession $session, array $data): IdentityVerificationSession;
}
