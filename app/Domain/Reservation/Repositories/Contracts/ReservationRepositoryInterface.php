<?php

namespace App\Domain\Reservation\Repositories\Contracts;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ReservationRepositoryInterface
{
    /**
     * Reservations belonging to $guest (guest_id === $guest->id), newest
     * first. The guest booking API's ownership boundary — a guest can only
     * ever see their own reservations. Data access only; no business rule.
     */
    public function paginateOwnedByGuest(Guest $guest, int $perPage = 15): LengthAwarePaginator;

    /**
     * Lookup by id scoped to $guest's ownership — returns null both when the
     * reservation does not exist and when it exists but belongs to another
     * guest, so the guest API answers an identical plain 404 for both and
     * never leaks existence.
     */
    public function findOwnedByGuest(Guest $guest, int $id): ?Reservation;

    /**
     * A guest's reservations, filtered through $user's own hotel access
     * (Group Owner bypass or assigned hotels only) — the staff-facing
     * counterpart to paginateOwnedByGuest, used by the Guests directory's
     * per-guest reservation history so a Hotel Manager only ever sees the
     * slice of a guest's history that belongs to their own hotel(s).
     */
    public function paginateForGuestAccessibleBy(User $user, Guest $guest, int $perPage = 15): LengthAwarePaginator;

    /**
     * Reservations filtered through $user's own hotel access (Group Owner
     * bypass or assigned hotels only) — never from a client-supplied
     * hotel_id. Not nested under a single Hotel, unlike Phase 2's
     * Room/RoomType listings: a Reservation route has not been designed
     * yet (Phase 3C), so this returns everything the user may see across
     * all their accessible hotels.
     */
    public function paginateAccessibleBy(User $user, int $perPage = 15): LengthAwarePaginator;

    /**
     * Lookup by id, scoped to $user's own hotel access — returns null both
     * when the reservation does not exist and when it exists but belongs
     * to a hotel the user cannot access, so a caller can never distinguish
     * the two from this method alone.
     */
    public function findAccessibleBy(User $user, int $id): ?Reservation;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Reservation;

    /**
     * Locks the Reservation row for the duration of the caller's
     * transaction (`SELECT ... FOR UPDATE`). Must only be called from
     * within an active DB::transaction(). This is the row lock the
     * status-transition workflow (Phase 4B) relies on so two concurrent
     * transitions cannot both act on stale state — the same
     * findForUpdate convention RoomRepository already exposes.
     */
    public function findForUpdate(int $id): ?Reservation;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Reservation $reservation, array $data): Reservation;

    /**
     * Count of blocking reservations (Reservation::BLOCKING_STATUSES) for
     * the exact physical Room $roomId whose date range overlaps
     * [$checkIn, $checkOut) under the canonical, checkout-exclusive
     * overlap predicate: existing.check_in < $checkOut AND $checkIn <
     * existing.check_out. Must be called only after the caller holds the
     * appropriate lock (approved Phase 3D concurrency design) — this
     * method performs a plain, non-locking read.
     */
    public function countOverlappingForRoom(int $roomId, string $checkIn, string $checkOut): int;

    /**
     * Count of blocking reservations (Reservation::BLOCKING_STATUSES) for
     * Room Type $roomTypeId whose date range overlaps [$checkIn,
     * $checkOut), regardless of whether each reservation has a room_id
     * assigned or not (approved Option A: assigned and unassigned
     * reservations both consume the Room Type's shared physical
     * capacity). Same overlap predicate and locking precondition as
     * countOverlappingForRoom().
     */
    public function countOverlappingForRoomType(int $roomTypeId, string $checkIn, string $checkOut): int;

    /**
     * countOverlappingForRoom() minus one reservation — used when (re)assigning
     * that reservation's own room, so it never conflicts with itself. Same
     * locking precondition.
     */
    public function countOverlappingForRoomExcluding(int $roomId, string $checkIn, string $checkOut, int $excludedReservationId): int;

    /**
     * The room ids already held by another blocking reservation of
     * $roomTypeId over [$checkIn, $checkOut) — excluding one reservation.
     * Plain read (no lock); the assignment itself re-checks under lock.
     *
     * @return list<int>
     */
    public function bookedRoomIdsForRoomType(int $roomTypeId, string $checkIn, string $checkOut, int $excludedReservationId): array;

    /**
     * The front-desk arrivals list: every non-cancelled reservation of
     * $hotelId whose check_in is exactly $date, newest-scheduled first.
     * No further status narrowing — a Pending/Deposit-held/Verified row is
     * still "expected to arrive"; its own status badge tells staff where
     * it stands.
     */
    public function paginateArrivalsForHotel(int $hotelId, string $date, int $perPage = 15): LengthAwarePaginator;

    /**
     * The front-desk departures list: every non-cancelled reservation of
     * $hotelId whose check_out is exactly $date.
     */
    public function paginateDeparturesForHotel(int $hotelId, string $date, int $perPage = 15): LengthAwarePaginator;

    /**
     * The front-desk in-house list: every reservation of $hotelId
     * currently occupying a room — IN_STAY or mid-checkout
     * (CHECKOUT_IN_PROGRESS / CHECKOUT_BLOCKED), regardless of date.
     */
    public function paginateInHouseForHotel(int $hotelId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Every blocking-status reservation of $hotelId whose date range
     * overlaps [$from, $to) (same checkout-exclusive predicate as
     * countOverlappingForRoom) — `check_in`/`check_out` only, for the
     * occupancy report's room-night calculation. Not paginated: report
     * aggregation needs the full set to sum, not a page of it.
     *
     * @return Collection<int, Reservation>
     */
    public function overlappingForHotel(int $hotelId, string $from, string $to): Collection;

    /**
     * Reservations of $hotelId eligible for the standalone folio ledger
     * (never cancelled — a cancelled stay has no live folio), newest
     * check-in first. `search` matches the reservation id (prefix) or its
     * guest's name/phone; `reservation_ids`, when present in $filters
     * (even as an empty array), restricts the result to exactly those ids
     * — the folio ledger's "outstanding only" filter, computed by
     * FolioService from the authoritative charge/payment totals.
     *
     * @param  array{search?: string|null, reservation_ids?: list<int>}  $filters
     */
    public function paginateForFolioLedger(int $hotelId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Reservation counts by status for $hotelId whose check_in falls in
     * [$from, $to] — the reservations report's data source.
     *
     * @return array<string, int>
     */
    public function countsByStatusForHotel(int $hotelId, string $from, string $to): array;
}
