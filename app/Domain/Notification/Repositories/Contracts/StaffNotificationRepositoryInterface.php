<?php

namespace App\Domain\Notification\Repositories\Contracts;

use App\Domain\Notification\Models\StaffNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StaffNotificationRepositoryInterface
{
    /**
     * Ids of active staff users who hold $permission and can access $hotelId
     * (group owners reach every hotel), excluding $exceptUserId.
     *
     * @return list<int>
     */
    public function recipientIdsForHotel(int $hotelId, string $permission, ?int $exceptUserId): array;

    /**
     * Insert one row per recipient; a row that already exists for the same
     * (user, audit entry) is skipped. Returns the number inserted.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $attributes  shared columns (hotel_id, reservation_id, audit_log_id, type, context)
     */
    public function createForRecipients(array $userIds, array $attributes): int;

    /**
     * @param  list<int>|null  $hotelIds  null = every hotel
     */
    public function paginateForUser(int $userId, ?array $hotelIds, bool $unreadOnly, int $perPage): LengthAwarePaginator;

    /**
     * @param  list<int>|null  $hotelIds  null = every hotel
     */
    public function countUnreadForUser(int $userId, ?array $hotelIds): int;

    public function findForUser(int $userId, int $id): ?StaffNotification;

    public function markRead(StaffNotification $notification): StaffNotification;

    /**
     * @param  list<int>|null  $hotelIds  null = every hotel
     */
    public function markAllReadForUser(int $userId, ?array $hotelIds): int;
}
