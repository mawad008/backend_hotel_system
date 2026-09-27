<?php

namespace App\Domain\Notification\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationRecipientType;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 11 — one notification record (Phase 0 §4 "Notifications", §15).
 *
 * Stored in `notification_events` (the §15 name, kept distinct from
 * Laravel's optional `notifications` table). One row per
 * (business event, recipient, channel).
 *
 * The persistent status vocabulary is the NotificationStatus enum; the
 * transition rules between statuses live ONLY in
 * NotificationDeliveryStateMachine — never here.
 *
 * Hotel scope is NOT applied via the HotelScoped trait: like Payment /
 * Digital Access / Loyalty, scope is resolved through the owning Reservation
 * and a Policy. `hotel_id` / `recipient_id` are denormalized server-side
 * from the Reservation and are never client-supplied.
 *
 * SECURITY: this row never stores a recipient contact value (email/phone),
 * a secret, a credential, or a raw provider payload. `subject` / `body` are
 * the rendered localized template text; `context` holds only safe scalars
 * (from/to status, reservation reference).
 */
class Notification extends Model
{
    use HasFactory;

    protected $table = 'notification_events';

    protected $fillable = [
        'reservation_id',
        'hotel_id',
        'recipient_type',
        'recipient_id',
        'type',
        'channel',
        'status',
        'locale',
        'subject',
        'body',
        'provider',
        'provider_reference',
        'provider_code',
        'failure_reason',
        'idempotency_key',
        'context',
        'sent_at',
        'failed_at',
        'read_at',
    ];

    protected $hidden = [
        'idempotency_key',
        'provider_reference',
    ];

    protected function casts(): array
    {
        return [
            'recipient_type' => NotificationRecipientType::class,
            'type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'context' => 'array',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
