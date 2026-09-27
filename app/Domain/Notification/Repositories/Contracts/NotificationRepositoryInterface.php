<?php

namespace App\Domain\Notification\Repositories\Contracts;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface NotificationRepositoryInterface
{
    public function find(int $id): ?Notification;

    /**
     * `find()` under a `SELECT ... FOR UPDATE` row lock. Call only from
     * within an active DB::transaction().
     */
    public function findForUpdate(int $id): ?Notification;

    public function findByIdempotencyKey(string $key): ?Notification;

    /**
     * `findByIdempotencyKey()` under a row lock — so a concurrent duplicate
     * dispatch cannot both discover "no row" and both insert. Call only
     * from within an active DB::transaction().
     */
    public function findByIdempotencyKeyForUpdate(string $key): ?Notification;

    /**
     * One notification that belongs to a specific reservation — null when it
     * does not exist or belongs to another reservation (so the caller can
     * never distinguish the two).
     */
    public function findForReservation(int $reservationId, int $notificationId): ?Notification;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Notification;

    /**
     * Pure persistence — the caller owns every workflow decision, including
     * the NotificationDeliveryStateMachine guard.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Notification $notification, array $data): Notification;

    /**
     * The reservation notification feed: rows for one channel, newest first,
     * optionally only the unread ones.
     *
     * @return LengthAwarePaginator<Notification>
     */
    public function paginateForReservationChannel(
        int $reservationId,
        NotificationChannel $channel,
        bool $unreadOnly,
        int $perPage,
    ): LengthAwarePaginator;

    /**
     * Mark every unread row for (reservation, channel) as read at $timestamp.
     * Returns the number of rows updated.
     */
    public function markReadForReservationChannel(
        int $reservationId,
        NotificationChannel $channel,
        string $timestamp,
    ): int;

    /**
     * The staff-wide `in_app` notification feed for a hotel — every guest
     * notification across the hotel's reservations, newest first.
     *
     * @param  array{unread_only?: bool}  $filters
     * @return LengthAwarePaginator<Notification>
     */
    public function paginateForHotelChannel(
        int $hotelId,
        NotificationChannel $channel,
        array $filters,
        int $perPage,
    ): LengthAwarePaginator;

    /**
     * The guest recipient's own `$channel` feed across every reservation
     * (newest first). Rows are matched on recipient type + id, never on a
     * client-supplied reservation.
     *
     * @return LengthAwarePaginator<Notification>
     */
    public function paginateForGuestChannel(
        int $guestId,
        NotificationChannel $channel,
        bool $unreadOnly,
        int $perPage,
    ): LengthAwarePaginator;

    public function countUnreadForGuestChannel(int $guestId, NotificationChannel $channel): int;

    /** One notification addressed to the guest recipient, or null. */
    public function findForGuest(int $guestId, int $notificationId): ?Notification;

    public function markReadForGuestChannel(int $guestId, NotificationChannel $channel, string $timestamp): int;
}
