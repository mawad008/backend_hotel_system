<?php

namespace App\Domain\Notification\Repositories;

use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Models\StaffNotification;
use App\Domain\Notification\Repositories\Contracts\StaffNotificationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentStaffNotificationRepository implements StaffNotificationRepositoryInterface
{
    public function recipientIdsForHotel(int $hotelId, string $permission, ?int $exceptUserId): array
    {
        return User::query()
            ->where('is_active', true)
            ->when($exceptUserId !== null, fn (Builder $q) => $q->whereKeyNot($exceptUserId))
            ->whereHas('role.permissions', fn (Builder $q) => $q->where('slug', $permission))
            ->where(fn (Builder $q) => $q
                ->whereHas('role', fn (Builder $r) => $r->where('slug', Role::GROUP_OWNER))
                ->orWhereHas('hotels', fn (Builder $h) => $h->whereKey($hotelId)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function createForRecipients(array $userIds, array $attributes): int
    {
        if ($userIds === []) {
            return 0;
        }

        $now = now();
        $context = isset($attributes['context']) ? json_encode($attributes['context']) : null;

        $rows = array_map(fn (int $userId) => [
            'user_id' => $userId,
            'hotel_id' => $attributes['hotel_id'],
            'reservation_id' => $attributes['reservation_id'] ?? null,
            'audit_log_id' => $attributes['audit_log_id'],
            'type' => $attributes['type'],
            'context' => $context,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userIds);

        return StaffNotification::query()->insertOrIgnore($rows);
    }

    public function paginateForUser(int $userId, ?array $hotelIds, bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        return $this->forUser($userId, $hotelIds)
            ->when($unreadOnly, fn (Builder $q) => $q->whereNull('read_at'))
            ->with('hotel:id,name,name_i18n')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function countUnreadForUser(int $userId, ?array $hotelIds): int
    {
        return $this->forUser($userId, $hotelIds)->whereNull('read_at')->count();
    }

    public function findForUser(int $userId, int $id): ?StaffNotification
    {
        return StaffNotification::query()
            ->where('user_id', $userId)
            ->with('hotel:id,name,name_i18n')
            ->find($id);
    }

    public function markRead(StaffNotification $notification): StaffNotification
    {
        $notification->forceFill(['read_at' => now()])->save();

        return $notification;
    }

    public function markAllReadForUser(int $userId, ?array $hotelIds): int
    {
        return $this->forUser($userId, $hotelIds)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  list<int>|null  $hotelIds
     * @return Builder<StaffNotification>
     */
    private function forUser(int $userId, ?array $hotelIds): Builder
    {
        return StaffNotification::query()
            ->where('user_id', $userId)
            ->when($hotelIds !== null, fn (Builder $q) => $q->whereIn('hotel_id', $hotelIds));
    }
}
