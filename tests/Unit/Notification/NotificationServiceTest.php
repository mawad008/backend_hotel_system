<?php

namespace Tests\Unit\Notification;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Exceptions\NotificationNotAllowedException;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Provider\Contracts\NotificationProviderInterface;
use App\Domain\Notification\Provider\Data\NotificationDeliveryResult;
use App\Domain\Notification\Provider\Data\NotificationDispatchRequest;
use App\Domain\Notification\Provider\NotificationDeliveryOutcome;
use App\Domain\Notification\Repositories\EloquentNotificationRepository;
use App\Domain\Notification\Services\NotificationRecipient;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    private function reservation(array $overrides = []): Reservation
    {
        $hotel = Hotel::factory()->create();

        return Reservation::factory()->create(array_merge([
            'hotel_id' => $hotel->id,
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ], $overrides));
    }

    private function service(NotificationProviderInterface $provider): NotificationService
    {
        return new NotificationService(
            $provider,
            new EloquentNotificationRepository,
            app(AuditLogger::class),
        );
    }

    private function dispatch(NotificationService $service, Reservation $reservation, string $eventKey = 'reservation:X:deposit_held'): Collection
    {
        return $service->dispatchForReservation(
            type: NotificationType::ReservationDepositHeld,
            reservation: $reservation,
            recipient: NotificationRecipient::fromGuest($reservation->guest),
            eventKey: $eventKey,
            context: ['from_status' => 'pending', 'to_status' => 'deposit_held'],
        );
    }

    public function test_first_dispatch_creates_one_sent_row_per_configured_channel(): void
    {
        $reservation = $this->reservation();

        $rows = $this->dispatch($this->service(new AlwaysSends), $reservation);

        // deposit_held routes to in_app + email (config).
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['in_app', 'email'],
            Notification::query()->pluck('channel')->map(fn ($c) => $c->value)->all(),
        );
        foreach (Notification::all() as $row) {
            $this->assertSame(NotificationStatus::Sent, $row->status);
            $this->assertNotNull($row->sent_at);
            $this->assertNotNull($row->provider_reference);
            $this->assertSame($reservation->id, $row->reservation_id);
            $this->assertSame($reservation->hotel_id, $row->hotel_id);
            $this->assertSame($reservation->guest_id, $row->recipient_id);
        }
    }

    public function test_recipient_and_hotel_are_resolved_from_the_reservation_not_the_caller(): void
    {
        $reservation = $this->reservation();
        $this->dispatch($this->service(new AlwaysSends), $reservation);

        $row = Notification::query()->first();
        $this->assertSame('guest', $row->recipient_type->value);
        $this->assertSame($reservation->guest_id, $row->recipient_id);
        // No recipient contact value is ever persisted.
        $this->assertStringNotContainsString('@', json_encode($row->getAttributes()));
    }

    public function test_a_repeated_dispatch_with_the_same_event_key_never_duplicates(): void
    {
        $reservation = $this->reservation();
        $service = $this->service(new AlwaysSends);

        $this->dispatch($service, $reservation);
        $first = Notification::query()->pluck('id')->all();

        $this->dispatch($service, $reservation);

        $this->assertSame($first, Notification::query()->pluck('id')->all());
        $this->assertSame(2, Notification::query()->count());
    }

    public function test_a_successful_notification_is_never_delivered_twice(): void
    {
        $reservation = $this->reservation();
        $provider = new CountingProvider(NotificationDeliveryOutcome::Sent);

        $service = $this->service($provider);
        $this->dispatch($service, $reservation);
        $this->dispatch($service, $reservation);
        $this->dispatch($service, $reservation);

        // One provider call per channel, ever.
        $this->assertSame(2, $provider->calls);
    }

    public function test_a_provider_failure_leaves_a_failed_row_that_a_retry_settles_exactly_once(): void
    {
        $reservation = $this->reservation();
        $provider = new SwitchableProvider(NotificationDeliveryOutcome::Failed);

        $service = $this->service($provider);
        $this->dispatch($service, $reservation);

        $this->assertSame(2, Notification::query()->where('status', 'failed')->count());
        foreach (Notification::all() as $row) {
            $this->assertSame('provider_declined', $row->failure_reason);
            $this->assertNull($row->sent_at);
            $this->assertNotNull($row->failed_at);
        }

        // Retry — provider now succeeds.
        $provider->outcome = NotificationDeliveryOutcome::Sent;
        $this->dispatch($service, $reservation);

        $this->assertSame(0, Notification::query()->where('status', 'failed')->count());
        $this->assertSame(2, Notification::query()->where('status', 'sent')->count());
        $this->assertSame(2, Notification::query()->count());
        // failed (2) + retry-requested (2) + sent (2), plus the created (2).
        $this->assertSame(2, AuditLog::query()->where('action', 'notification.retry_requested')->count());
    }

    public function test_the_provider_is_never_called_inside_an_open_transaction(): void
    {
        $reservation = $this->reservation();
        $guard = new TransactionLevelGuard;

        $this->dispatch($this->service($guard), $reservation);

        foreach ($guard->levels as $level) {
            $this->assertSame($guard->baseline, $level, 'provider was called inside a nested transaction');
        }
        $this->assertNotEmpty($guard->levels);
    }

    public function test_delivery_status_changes_go_through_the_state_machine(): void
    {
        $code = file_get_contents((new \ReflectionClass(NotificationService::class))->getFileName());

        $this->assertStringContainsString('NotificationDeliveryStateMachine::assertCanTransition(', $code);
        $this->assertStringNotContainsString('TRANSITIONS', $code);
    }

    public function test_audit_rows_carry_no_secret_no_key_and_no_address(): void
    {
        $reservation = $this->reservation();
        $this->dispatch($this->service(new AlwaysSends), $reservation, 'reservation:'.$reservation->id.':deposit_held');

        foreach (AuditLog::query()->where('action', 'like', 'notification.%')->get() as $log) {
            $blob = json_encode($log->after);
            $this->assertStringNotContainsString('idempotency', strtolower($blob));
            $this->assertStringNotContainsString('@', $blob);
            $this->assertArrayHasKey('notification_type', $log->after);
        }
    }

    public function test_mark_read_is_in_app_only_and_idempotent(): void
    {
        $reservation = $this->reservation();
        $service = $this->service(new AlwaysSends);
        $this->dispatch($service, $reservation);

        $inApp = Notification::query()->where('channel', 'in_app')->first();
        $email = Notification::query()->where('channel', 'email')->first();

        $read = $service->markRead($inApp);
        $this->assertNotNull($read->read_at);
        $stamp = $read->read_at;

        // idempotent — same timestamp, no change.
        $this->assertEquals($stamp, $service->markRead($inApp->fresh())->read_at);

        $this->expectException(NotificationNotAllowedException::class);
        $service->markRead($email);
    }

    public function test_concurrent_duplicate_inserts_are_safe(): void
    {
        $reservation = $this->reservation();
        $service = $this->service(new AlwaysSends);

        // Simulate the row a concurrent request already committed.
        Notification::factory()->forReservation($reservation)->channel(NotificationChannel::InApp)->create([
            'idempotency_key' => 'reservation:'.$reservation->id.':deposit_held:in_app',
            'type' => NotificationType::ReservationDepositHeld->value,
        ]);

        $this->dispatch($service, $reservation, 'reservation:'.$reservation->id.':deposit_held');

        // in_app row is the pre-existing one (replayed), email row is new.
        $this->assertSame(1, Notification::query()->where('channel', 'in_app')->count());
        $this->assertSame(1, Notification::query()->where('channel', 'email')->count());
    }
}

class AlwaysSends implements NotificationProviderInterface
{
    public function send(NotificationDispatchRequest $request): NotificationDeliveryResult
    {
        return new NotificationDeliveryResult(
            NotificationDeliveryOutcome::Sent,
            'dummy_ref_'.$request->channel->value,
            'dummy_'.$request->channel->value.'_sent',
            'ok',
        );
    }
}

class CountingProvider implements NotificationProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly NotificationDeliveryOutcome $outcome) {}

    public function send(NotificationDispatchRequest $request): NotificationDeliveryResult
    {
        $this->calls++;

        return new NotificationDeliveryResult($this->outcome, 'ref', 'code', 'ok');
    }
}

class SwitchableProvider implements NotificationProviderInterface
{
    public function __construct(public NotificationDeliveryOutcome $outcome) {}

    public function send(NotificationDispatchRequest $request): NotificationDeliveryResult
    {
        return new NotificationDeliveryResult($this->outcome, 'ref_'.$request->channel->value, 'code', 'ok');
    }
}

class TransactionLevelGuard implements NotificationProviderInterface
{
    public int $baseline;

    /** @var list<int> */
    public array $levels = [];

    public function __construct()
    {
        $this->baseline = DB::transactionLevel();
    }

    public function send(NotificationDispatchRequest $request): NotificationDeliveryResult
    {
        $this->levels[] = DB::transactionLevel();

        return new NotificationDeliveryResult(NotificationDeliveryOutcome::Sent, 'ref', 'code', 'ok');
    }
}
