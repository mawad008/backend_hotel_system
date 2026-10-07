<?php

namespace App\Providers;

use App\Domain\Audit\Events\AuditRecorded;
use App\Domain\Notification\Listeners\NotifyStaffOfOperationalEvent;
use App\Domain\Notification\Listeners\SendReservationLifecycleNotifications;
use App\Domain\Notification\Models\StaffNotification;
use App\Domain\Notification\Policies\StaffNotificationPolicy;
use App\Domain\Notification\Provider\Contracts\NotificationProviderInterface;
use App\Domain\Notification\Provider\DummyNotificationProvider;
use App\Domain\Notification\Provider\Exceptions\UnsupportedNotificationProviderException;
use App\Domain\Notification\Provider\SimulationDirective;
use App\Domain\Notification\Repositories\Contracts\StaffNotificationRepositoryInterface;
use App\Domain\Notification\Repositories\EloquentStaffNotificationRepository;
use App\Domain\Reservation\Events\ReservationStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 11 — wires the Notification domain.
 *
 * The provider boundary (NotificationProviderInterface) is bound the same
 * way as the payment / identity / access boundaries: the concrete provider
 * is chosen from config('notifications.provider'), "dummy" is the only
 * implementation registered this phase, and an explicitly configured but
 * unsupported provider fails loudly rather than silently falling back.
 *
 * The single event seam (ReservationStatusChanged -> the notification
 * listener) is registered here rather than in AppServiceProvider to keep the
 * notification wiring self-contained.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StaffNotificationRepositoryInterface::class, EloquentStaffNotificationRepository::class);

        $this->app->singleton(NotificationProviderInterface::class, function (): NotificationProviderInterface {
            $provider = (string) config('notifications.provider');

            return match ($provider) {
                'dummy' => new DummyNotificationProvider(
                    defaultDirective: SimulationDirective::fromConfig(
                        config('notifications.providers.dummy.default_directive'),
                    ),
                ),
                default => throw new UnsupportedNotificationProviderException($provider),
            };
        });
    }

    public function boot(): void
    {
        Event::listen(ReservationStatusChanged::class, SendReservationLifecycleNotifications::class);

        // Dashboard staff inbox — fed by the audit trail every domain
        // already writes (see StaffNotificationType for the allow-list).
        Event::listen(AuditRecorded::class, NotifyStaffOfOperationalEvent::class);
        Gate::policy(StaffNotification::class, StaffNotificationPolicy::class);
    }
}
