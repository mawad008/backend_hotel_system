<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Audit\Events\AuditRecorded;
use App\Domain\Notification\Services\StaffNotificationService;
use Throwable;

/**
 * Turns an audited operational action into dashboard staff-inbox rows. A
 * notification is a side effect: any failure is reported and swallowed so it
 * can never block the action that produced it.
 */
class NotifyStaffOfOperationalEvent
{
    public function __construct(private readonly StaffNotificationService $staffNotifications) {}

    public function handle(AuditRecorded $event): void
    {
        try {
            $this->staffNotifications->notifyForAudit($event->log, $event->subject);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
