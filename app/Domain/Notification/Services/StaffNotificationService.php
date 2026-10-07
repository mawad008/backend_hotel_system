<?php

namespace App\Domain\Notification\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Enums\StaffNotificationType;
use App\Domain\Notification\Models\StaffNotification;
use App\Domain\Notification\Repositories\Contracts\StaffNotificationRepositoryInterface;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The dashboard staff inbox.
 *
 * Fan-out: an audited operational action (StaffNotificationType maps the
 * allow-listed actions) produces one in-app row for every active staff user
 * who holds `notifications.view` and can access the action's hotel — except
 * the user who performed it. Pure DB writes, no provider: safe to run inside
 * the acting domain's transaction.
 *
 * Reads are recipient-scoped (the authenticated user's own rows only) and
 * re-filtered by the user's *current* hotel access, so losing access to a
 * hotel also hides its earlier notifications.
 */
class StaffNotificationService
{
    public const PERMISSION = 'notifications.view';

    public function __construct(private readonly StaffNotificationRepositoryInterface $notifications) {}

    /**
     * Returns the number of rows created (0 when the action is not a staff
     * notification event or has no hotel).
     */
    public function notifyForAudit(AuditLog $log, ?Model $subject): int
    {
        $type = StaffNotificationType::forAuditAction($log->action);

        if ($type === null || $log->hotel_id === null) {
            return 0;
        }

        $recipients = $this->notifications->recipientIdsForHotel(
            (int) $log->hotel_id,
            self::PERMISSION,
            $log->actor_id !== null ? (int) $log->actor_id : null,
        );

        return $this->notifications->createForRecipients($recipients, [
            'hotel_id' => (int) $log->hotel_id,
            'reservation_id' => $this->reservationIdOf($subject),
            'audit_log_id' => $log->id,
            'type' => $type->value,
            'context' => array_filter([
                'subject_id' => $subject?->getKey(),
            ], fn ($v) => $v !== null),
        ]);
    }

    public function listFor(User $user, bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        return $this->notifications->paginateForUser($user->id, $this->hotelScope($user), $unreadOnly, $perPage);
    }

    public function unreadCountFor(User $user): int
    {
        return $this->notifications->countUnreadForUser($user->id, $this->hotelScope($user));
    }

    /**
     * Idempotent. Another user's id, or one for a hotel the user can no
     * longer access, is an identical 404.
     *
     * @throws ModelNotFoundException
     */
    public function markReadFor(User $user, int $id): StaffNotification
    {
        $notification = $this->notifications->findForUser($user->id, $id);
        $scope = $this->hotelScope($user);

        if ($notification === null || ($scope !== null && ! in_array($notification->hotel_id, $scope, true))) {
            throw (new ModelNotFoundException)->setModel(StaffNotification::class, [$id]);
        }

        if ($notification->read_at !== null) {
            return $notification;
        }

        return $this->notifications->markRead($notification);
    }

    public function markAllReadFor(User $user): int
    {
        return $this->notifications->markAllReadForUser($user->id, $this->hotelScope($user));
    }

    /**
     * @return list<int>|null null = every hotel (group owner)
     */
    private function hotelScope(User $user): ?array
    {
        if ($user->isGroupOwner()) {
            return null;
        }

        return array_map('intval', $user->authorizedHotelIds());
    }

    private function reservationIdOf(?Model $subject): ?int
    {
        if ($subject instanceof Reservation) {
            return (int) $subject->getKey();
        }

        $id = $subject?->getAttribute('reservation_id');

        return $id !== null ? (int) $id : null;
    }
}
