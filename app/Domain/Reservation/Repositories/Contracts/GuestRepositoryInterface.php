<?php

namespace App\Domain\Reservation\Repositories\Contracts;

use App\Domain\Reservation\Models\Guest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface GuestRepositoryInterface
{
    /**
     * Staff-facing directory listing — every guest, newest first, with a
     * reservation count for a useful list view. Not hotel-scoped (a Guest
     * is not hotel-bound); authorization is the caller's responsibility.
     *
     * @param  array{search?: string|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Plain lookup by id — no authorization decision is made here, that
     * is the caller's responsibility.
     */
    public function find(int $id): ?Guest;

    /**
     * Lookup by the canonical E.164 phone (the guest login identifier).
     */
    public function findByPhone(string $phone): ?Guest;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Guest;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Guest $guest, array $data): Guest;
}
