<?php

namespace App\Domain\Notification\Repositories;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationRecipientType;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentNotificationRepository implements NotificationRepositoryInterface
{
    public function find(int $id): ?Notification
    {
        return Notification::query()->find($id);
    }

    public function findForUpdate(int $id): ?Notification
    {
        return Notification::query()->lockForUpdate()->find($id);
    }

    public function findByIdempotencyKey(string $key): ?Notification
    {
        return Notification::query()->where('idempotency_key', $key)->first();
    }

    public function findByIdempotencyKeyForUpdate(string $key): ?Notification
    {
        return Notification::query()->where('idempotency_key', $key)->lockForUpdate()->first();
    }

    public function findForReservation(int $reservationId, int $notificationId): ?Notification
    {
        return Notification::query()
            ->where('reservation_id', $reservationId)
            ->whereKey($notificationId)
            ->first();
    }

    public function create(array $data): Notification
    {
        return Notification::create($data)->refresh();
    }

    public function update(Notification $notification, array $data): Notification
    {
        $notification->update($data);

        return $notification->refresh();
    }

    public function paginateForReservationChannel(
        int $reservationId,
        NotificationChannel $channel,
        bool $unreadOnly,
        int $perPage,
    ): LengthAwarePaginator {
        return Notification::query()
            ->where('reservation_id', $reservationId)
            ->where('channel', $channel->value)
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function markReadForReservationChannel(
        int $reservationId,
        NotificationChannel $channel,
        string $timestamp,
    ): int {
        return Notification::query()
            ->where('reservation_id', $reservationId)
            ->where('channel', $channel->value)
            ->whereNull('read_at')
            ->update(['read_at' => $timestamp]);
    }

    public function paginateForHotelChannel(
        int $hotelId,
        NotificationChannel $channel,
        array $filters,
        int $perPage,
    ): LengthAwarePaginator {
        return Notification::query()
            ->where('hotel_id', $hotelId)
            ->where('channel', $channel->value)
            ->when($filters['unread_only'] ?? false, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateForGuestChannel(
        int $guestId,
        NotificationChannel $channel,
        bool $unreadOnly,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->guestQuery($guestId, $channel)
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function countUnreadForGuestChannel(int $guestId, NotificationChannel $channel): int
    {
        return $this->guestQuery($guestId, $channel)->whereNull('read_at')->count();
    }

    public function findForGuest(int $guestId, int $notificationId): ?Notification
    {
        return Notification::query()
            ->where('recipient_type', NotificationRecipientType::Guest->value)
            ->where('recipient_id', $guestId)
            ->whereKey($notificationId)
            ->first();
    }

    public function markReadForGuestChannel(int $guestId, NotificationChannel $channel, string $timestamp): int
    {
        return $this->guestQuery($guestId, $channel)
            ->whereNull('read_at')
            ->update(['read_at' => $timestamp]);
    }

    /** @return Builder<Notification> */
    private function guestQuery(int $guestId, NotificationChannel $channel): Builder
    {
        return Notification::query()
            ->where('recipient_type', NotificationRecipientType::Guest->value)
            ->where('recipient_id', $guestId)
            ->where('channel', $channel->value);
    }
}
