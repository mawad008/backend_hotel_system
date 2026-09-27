<?php

namespace App\Domain\Payment\Models;

use Database\Factories\PaymentWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The normalized inbound webhook/callback boundary (Phase 0 §9/§16,
 * Phase 5 plan v2 §7.3). Stores a sanitized normalized representation and a
 * hash of the raw body — never the raw provider request body, never any
 * credential/secret.
 *
 * Deduplication (Phase 5 plan v2): `provider_event_id` is the primary key
 * when present; `payload_hash` backs a best-effort fallback lookup only
 * when it is absent, and is therefore NOT universally unique.
 *
 * Phase 5A: schema/relationship foundation only — the signature check,
 * parsing, dedup logic and controller are a later sub-phase.
 */
class PaymentWebhookEvent extends Model
{
    use HasFactory;

    public const PROCESSING_RECEIVED = 'received';

    public const PROCESSING_PROCESSED = 'processed';

    public const PROCESSING_DUPLICATE_IGNORED = 'duplicate_ignored';

    public const PROCESSING_UNMATCHED = 'unmatched';

    public const PROCESSING_FAILED = 'failed';

    /**
     * @var array<int, string>
     */
    public const PROCESSING_STATUSES = [
        self::PROCESSING_RECEIVED,
        self::PROCESSING_PROCESSED,
        self::PROCESSING_DUPLICATE_IGNORED,
        self::PROCESSING_UNMATCHED,
        self::PROCESSING_FAILED,
    ];

    protected $fillable = [
        'provider',
        'provider_event_id',
        'payload_hash',
        'event_type',
        'processing_status',
        'payment_id',
        'provider_reference',
        'normalized_payload',
        'processed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'normalized_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The matched local Payment, if any — NULL while the event is
     * unmatched (it may arrive before or without a local Payment).
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    protected static function newFactory(): PaymentWebhookEventFactory
    {
        return PaymentWebhookEventFactory::new();
    }
}
