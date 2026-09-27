<?php

namespace App\Domain\Notification\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Exceptions\NotificationNotAllowedException;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Provider\Contracts\NotificationProviderInterface;
use App\Domain\Notification\Provider\Data\NotificationDeliveryResult;
use App\Domain\Notification\Provider\Data\NotificationDispatchRequest;
use App\Domain\Notification\Provider\SimulationDirective;
use App\Domain\Notification\Provider\Support\NotificationSensitiveDataGuard;
use App\Domain\Notification\Repositories\Contracts\NotificationRepositoryInterface;
use App\Domain\Notification\StateMachine\NotificationDeliveryStateMachine;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 — the notification workflow (Phase 0 §4 "Notifications", §15).
 *
 * ══ Absolute architectural rule ══
 * The provider (NotificationProviderInterface) is NEVER called while a
 * database transaction is open. Every delivery is three explicit stages,
 * mirroring the Phase 5 / 7 / 9 provider workflows:
 *
 *   STEP A  (DB transaction)   idempotency pre-check under a row lock,
 *                              render the localized message, persist the row
 *                              PENDING, advance it to SENDING, audit, COMMIT.
 *   STEP B  (no transaction)   NotificationProviderInterface::send.
 *   STEP C  (DB transaction)   re-lock, apply the normalized result, transition
 *                              through NotificationDeliveryStateMachine, audit,
 *                              COMMIT.
 *
 * ══ Idempotency ══
 * The same business event never creates a duplicate row: the caller supplies
 * a deterministic `$eventKey` and every channel row is keyed
 * `"{$eventKey}:{$channel}"` against the `notification_events.idempotency_key`
 * UNIQUE. A repeated event, a retried request, and a concurrent double all
 * collide there. A SENT row is never re-sent; a FAILED row can be retried
 * safely and settles on exactly one successful delivery.
 *
 * ══ Recipient ══
 * Resolved server-side only (a NotificationRecipient built from the
 * Reservation's Guest). No client-supplied recipient id or address. The
 * recipient's real email/phone never touches this service beyond building an
 * opaque provider routing token, and is never persisted.
 *
 * This service is synchronous — the project has no queue infrastructure yet.
 * Queued, after-commit dispatch is a documented future enhancement; the
 * provider abstraction and the staged shape already accommodate it.
 */
class NotificationService
{
    public function __construct(
        private readonly NotificationProviderInterface $provider,
        private readonly NotificationRepositoryInterface $notifications,
        private readonly AuditLogger $auditLogger,
    ) {}

    // ═════════════════════════════════════════════════════════════════════
    //  Dispatch (staged)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Compose and deliver a notification of $type to $recipient for
     * $reservation, fanned out across the channels configured for $type. One
     * row per channel. Never throws for a provider failure — that row is left
     * FAILED and is safe to redispatch with the same $eventKey.
     *
     * MUST NOT be called from inside an open DB transaction.
     *
     * @param  array<string, scalar|null>  $context  safe, non-secret context
     * @return Collection<int, Notification>
     */
    public function dispatchForReservation(
        NotificationType $type,
        Reservation $reservation,
        NotificationRecipient $recipient,
        string $eventKey,
        array $context = [],
        ?string $locale = null,
        ?SimulationDirective $directive = null,
        ?User $actor = null,
    ): Collection {
        NotificationSensitiveDataGuard::assertClean($context, 'dispatch');

        $locale = $this->resolveLocale($locale);
        $rendered = $this->render($type, $locale, $reservation);

        $results = new Collection;

        foreach ($this->channelsFor($type, $recipient) as $channel) {
            $results->push($this->deliverOnChannel(
                type: $type,
                reservation: $reservation,
                recipient: $recipient,
                channel: $channel,
                idempotencyKey: $eventKey.':'.$channel->value,
                locale: $locale,
                subject: $rendered['subject'],
                body: $rendered['body'],
                context: $context,
                directive: $directive,
                actor: $actor,
            ));
        }

        return $results;
    }

    private function deliverOnChannel(
        NotificationType $type,
        Reservation $reservation,
        NotificationRecipient $recipient,
        NotificationChannel $channel,
        string $idempotencyKey,
        string $locale,
        string $subject,
        string $body,
        array $context,
        ?SimulationDirective $directive,
        ?User $actor,
    ): Notification {
        $stepA = $this->openDelivery(
            $type, $reservation, $recipient, $channel, $idempotencyKey, $locale, $subject, $body, $context, $actor,
        );

        if ($stepA['action'] === 'replay') {
            return $stepA['notification'];
        }

        // ── STEP B ── provider call, OUTSIDE any DB transaction.
        $result = $this->provider->send(new NotificationDispatchRequest(
            channel: $channel,
            destinationReference: $recipient->destinationReference($channel),
            type: $type->value,
            locale: $locale,
            subject: $subject,
            body: $body,
            directive: $directive,
            context: $context,
        ));

        // ── STEP C ──
        return $this->applyDeliveryResult($stepA['notification']->id, $result, $actor);
    }

    /**
     * STEP A — no provider call. One transaction that fully commits or fully
     * rolls back.
     *
     * @return array{action: 'send'|'replay', notification: Notification}
     */
    private function openDelivery(
        NotificationType $type,
        Reservation $reservation,
        NotificationRecipient $recipient,
        NotificationChannel $channel,
        string $idempotencyKey,
        string $locale,
        string $subject,
        string $body,
        array $context,
        ?User $actor,
    ): array {
        return DB::transaction(function () use (
            $type, $reservation, $recipient, $channel, $idempotencyKey, $locale, $subject, $body, $context, $actor,
        ) {
            $existing = $this->notifications->findByIdempotencyKeyForUpdate($idempotencyKey);

            if ($existing !== null) {
                return $this->decideReplay($existing, $actor);
            }

            try {
                $notification = $this->notifications->create([
                    'reservation_id' => $reservation->id,
                    'hotel_id' => $reservation->hotel_id,
                    'recipient_type' => $recipient->type->value,
                    'recipient_id' => $recipient->id,
                    'type' => $type->value,
                    'channel' => $channel->value,
                    'status' => NotificationStatus::Pending->value,
                    'locale' => $locale,
                    'subject' => $subject,
                    'body' => $body,
                    'provider' => $this->providerName(),
                    'idempotency_key' => $idempotencyKey,
                    'context' => $context + [
                        'reservation_reference' => '#'.$reservation->id,
                    ],
                ]);
            } catch (UniqueConstraintViolationException) {
                $winner = $this->notifications->findByIdempotencyKeyForUpdate($idempotencyKey)
                    ?? throw NotificationNotAllowedException::deliveryRaceUnresolved();

                return $this->decideReplay($winner, $actor);
            }

            $notification = $this->advance($notification, NotificationStatus::Sending);

            $this->auditLogger->record(
                $actor,
                'notification.created',
                $notification,
                after: $this->auditSnapshot($notification),
                hotelId: $notification->hotel_id,
            );

            return ['action' => 'send', 'notification' => $notification];
        });
    }

    /**
     * What to do with a row that already exists for this idempotency key:
     *   SENT     -> replay (idempotent success)
     *   SENDING  -> replay (another attempt is in flight)
     *   PENDING  -> resume (advance to SENDING and send)
     *   FAILED   -> retry  (advance to SENDING and send)
     *
     * @return array{action: 'send'|'replay', notification: Notification}
     */
    private function decideReplay(Notification $notification, ?User $actor): array
    {
        return match ($notification->status) {
            NotificationStatus::Sent,
            NotificationStatus::Sending => ['action' => 'replay', 'notification' => $notification],
            NotificationStatus::Pending => ['action' => 'send', 'notification' => $this->advance($notification, NotificationStatus::Sending)],
            NotificationStatus::Failed => ['action' => 'send', 'notification' => $this->retry($notification, $actor)],
        };
    }

    private function retry(Notification $notification, ?User $actor): Notification
    {
        $notification = $this->advance($notification, NotificationStatus::Sending, [
            'failure_reason' => null,
        ]);

        $this->auditLogger->record(
            $actor,
            'notification.retry_requested',
            $notification,
            after: $this->auditSnapshot($notification),
            hotelId: $notification->hotel_id,
        );

        return $notification;
    }

    /**
     * STEP C — the reusable "apply a provider delivery result" capability.
     * Fully idempotent: a result for a row that is no longer SENDING is a
     * no-op that returns the current row. No provider call happens here.
     */
    public function applyDeliveryResult(
        int $notificationId,
        NotificationDeliveryResult $result,
        ?User $actor = null,
    ): Notification {
        return DB::transaction(function () use ($notificationId, $result, $actor) {
            $notification = $this->notifications->findForUpdate($notificationId);

            if ($notification === null) {
                throw (new ModelNotFoundException)->setModel(Notification::class, [$notificationId]);
            }

            if ($notification->status !== NotificationStatus::Sending) {
                return $notification;
            }

            if ($result->isFailure()) {
                $notification = $this->advance($notification, NotificationStatus::Failed, [
                    'failure_reason' => 'provider_declined',
                    'provider_reference' => $result->providerReference,
                    'provider_code' => $result->providerCode,
                    'failed_at' => now(),
                    'context' => $this->mergedContext($notification, $result),
                ]);

                $this->auditLogger->record(
                    $actor,
                    'notification.failed',
                    $notification,
                    after: $this->auditSnapshot($notification),
                    hotelId: $notification->hotel_id,
                );

                return $notification;
            }

            $notification = $this->advance($notification, NotificationStatus::Sent, [
                'provider_reference' => $result->providerReference,
                'provider_code' => $result->providerCode,
                'failure_reason' => null,
                'sent_at' => now(),
                'context' => $this->mergedContext($notification, $result),
            ]);

            $this->auditLogger->record(
                $actor,
                'notification.sent',
                $notification,
                after: $this->auditSnapshot($notification),
                hotelId: $notification->hotel_id,
            );

            return $notification;
        });
    }

    // ═════════════════════════════════════════════════════════════════════
    //  In-app feed
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return LengthAwarePaginator<Notification>
     */
    public function listForReservation(Reservation $reservation, bool $unreadOnly = false, int $perPage = 20): LengthAwarePaginator
    {
        return $this->notifications->paginateForReservationChannel(
            $reservation->id,
            NotificationChannel::InApp,
            $unreadOnly,
            $perPage,
        );
    }

    /**
     * The staff-wide `in_app` feed for a hotel — a pure read, no workflow
     * decision.
     *
     * @param  array{unread_only?: bool}  $filters
     * @return LengthAwarePaginator<Notification>
     */
    public function listForHotel(int $hotelId, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->notifications->paginateForHotelChannel($hotelId, NotificationChannel::InApp, $filters, $perPage);
    }

    /**
     * Resolve one notification that belongs to $reservation and mark it read.
     * A missing id, or an id belonging to another reservation, is an
     * identical ModelNotFoundException (rendered as a plain 404).
     *
     * @throws ModelNotFoundException
     * @throws NotificationNotAllowedException
     */
    public function markReadFor(Reservation $reservation, int $notificationId): Notification
    {
        $notification = $this->notifications->findForReservation($reservation->id, $notificationId);

        if ($notification === null) {
            throw (new ModelNotFoundException)->setModel(Notification::class, [$notificationId]);
        }

        return $this->markRead($notification);
    }

    /**
     * Mark one in-app notification read. Idempotent. A non-in_app row cannot
     * be marked read.
     *
     * @throws NotificationNotAllowedException
     */
    public function markRead(Notification $notification): Notification
    {
        if ($notification->channel !== NotificationChannel::InApp) {
            throw NotificationNotAllowedException::notReadable();
        }

        if ($notification->read_at !== null) {
            return $notification;
        }

        return $this->notifications->update($notification, ['read_at' => now()]);
    }

    /**
     * Mark every unread in-app notification for a reservation read. Returns
     * the number of rows updated.
     */
    public function markAllReadForReservation(Reservation $reservation): int
    {
        return $this->notifications->markReadForReservationChannel(
            $reservation->id,
            NotificationChannel::InApp,
            (string) now(),
        );
    }

    /**
     * The guest's own `in_app` feed across all of their reservations — the
     * Guest App's notifications screen. Recipient-scoped (guest type + id),
     * so staff-addressed rows never appear.
     *
     * @return LengthAwarePaginator<Notification>
     */
    public function listForGuest(Guest $guest, bool $unreadOnly = false, int $perPage = 20): LengthAwarePaginator
    {
        return $this->notifications->paginateForGuestChannel($guest->id, NotificationChannel::InApp, $unreadOnly, $perPage);
    }

    public function unreadCountForGuest(Guest $guest): int
    {
        return $this->notifications->countUnreadForGuestChannel($guest->id, NotificationChannel::InApp);
    }

    /**
     * Mark one of the guest's own notifications read. Another recipient's id
     * is an identical 404.
     */
    public function markReadForGuest(Guest $guest, int $notificationId): Notification
    {
        $notification = $this->notifications->findForGuest($guest->id, $notificationId);

        if ($notification === null) {
            throw (new ModelNotFoundException)->setModel(Notification::class, [$notificationId]);
        }

        return $this->markRead($notification);
    }

    public function markAllReadForGuest(Guest $guest): int
    {
        return $this->notifications->markReadForGuestChannel($guest->id, NotificationChannel::InApp, (string) now());
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Internals
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @param  array<string, mixed>  $extra
     */
    private function advance(Notification $notification, NotificationStatus $to, array $extra = []): Notification
    {
        NotificationDeliveryStateMachine::assertCanTransition($notification->status, $to);

        return $this->notifications->update($notification, ['status' => $to->value] + $extra);
    }

    /**
     * The channels a $type is delivered on: the configured routing for the
     * type, limited to the globally-enabled channels and to what the
     * recipient can actually receive.
     *
     * @return list<NotificationChannel>
     */
    private function channelsFor(NotificationType $type, NotificationRecipient $recipient): array
    {
        $enabled = (array) config('notifications.channels.enabled', []);
        $routed = (array) config('notifications.routing.'.$type->value, []);

        $channels = [];

        foreach ($routed as $value) {
            $channel = NotificationChannel::tryFrom((string) $value);

            if ($channel === null
                || ! in_array($channel->value, $enabled, true)
                || ! $recipient->canReceiveOn($channel)) {
                continue;
            }

            $channels[] = $channel;
        }

        return $channels;
    }

    /**
     * Render the localized subject + body for a type. Templates are vetted
     * localization strings — never free-form provider text, never an invented
     * business claim.
     *
     * @return array{subject: string, body: string}
     */
    private function render(NotificationType $type, string $locale, Reservation $reservation): array
    {
        $params = ['reference' => '#'.$reservation->id];
        $key = $type->translationKey();

        return [
            'subject' => (string) __($key.'.subject', $params, $locale),
            'body' => (string) __($key.'.body', $params, $locale),
        ];
    }

    private function resolveLocale(?string $locale): string
    {
        $available = (array) config('app.available_locales', ['en']);
        $fallback = (string) (config('notifications.locale') ?: config('app.locale', 'en'));

        if ($locale !== null && in_array($locale, $available, true)) {
            return $locale;
        }

        return in_array($fallback, $available, true) ? $fallback : 'en';
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedContext(Notification $notification, NotificationDeliveryResult $result): array
    {
        return array_merge($notification->context ?? [], [
            'provider_code' => $result->providerCode,
            'provider_message' => $result->message,
        ]);
    }

    private function providerName(): string
    {
        return (string) config('notifications.provider', 'dummy');
    }

    /**
     * A safe, flat snapshot for the audit trail — business status fields
     * only. NEVER the idempotency key, a recipient address, a raw provider
     * payload, or any secret (Phase 0 §17).
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(Notification $notification): array
    {
        return array_filter([
            'notification_status' => $notification->status->value,
            'notification_type' => $notification->type->value,
            'channel' => $notification->channel->value,
            'recipient_type' => $notification->recipient_type->value,
            'locale' => $notification->locale,
            'provider' => $notification->provider,
            'provider_reference' => $notification->provider_reference,
            'failure_reason' => $notification->failure_reason,
            'sent_at' => $notification->sent_at?->toIso8601String(),
            'failed_at' => $notification->failed_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
