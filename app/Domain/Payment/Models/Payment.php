<?php

namespace App\Domain\Payment\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The money-lifecycle record for a single Reservation (Phase 0 §6.1,
 * approved Phase 5 plan v2). Phase 5A is schema/domain foundation only:
 * this model carries the persistent status vocabulary and relationships —
 * the hold/capture/settlement workflow, the provider abstraction, and the
 * HTTP surface are later sub-phases (5B–5F) and are deliberately absent.
 *
 * Hotel scope is NOT applied via the HotelScoped trait here — the
 * Reservation domain resolves scope through the owning record and a
 * Policy, and Payment follows the same reasoning (Phase 5 plan v2 §14.3).
 */
class Payment extends Model
{
    use HasFactory;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_HOLD_REQUESTED = 'hold_requested';

    public const STATUS_HOLD_ACTIVE = 'hold_active';

    public const STATUS_HOLD_FAILED = 'hold_failed';

    public const STATUS_CAPTURE_REQUESTED = 'capture_requested';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_CAPTURE_FAILED = 'capture_failed';

    public const STATUS_FINAL_SETTLEMENT_REQUESTED = 'final_settlement_requested';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_SETTLEMENT_FAILED = 'settlement_failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUND_REQUESTED = 'refund_requested';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_REFUND_FAILED = 'refund_failed';

    /**
     * Every approved persistent Payment status, in lifecycle order
     * (Phase 0 §9). The transition rules between them live only in
     * PaymentStateMachine — never here.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_NOT_STARTED,
        self::STATUS_HOLD_REQUESTED,
        self::STATUS_HOLD_ACTIVE,
        self::STATUS_HOLD_FAILED,
        self::STATUS_CAPTURE_REQUESTED,
        self::STATUS_CAPTURED,
        self::STATUS_CAPTURE_FAILED,
        self::STATUS_FINAL_SETTLEMENT_REQUESTED,
        self::STATUS_SETTLED,
        self::STATUS_SETTLEMENT_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
        self::STATUS_REFUND_REQUESTED,
        self::STATUS_REFUNDED,
        self::STATUS_REFUND_FAILED,
    ];

    /**
     * Statuses in which the Payment represents money actually captured or
     * settled — not a mere authorization hold, not pending, not failed
     * (Phase 0 §9). The folio's payments total is derived from these and
     * these only; a HOLD_ACTIVE deposit is an authorization, not money in.
     * Kept here — beside the status vocabulary — so no consumer redefines
     * payment state semantics of its own (Phase 8D).
     *
     * @var array<int, string>
     */
    public const CAPTURED_STATUSES = [
        self::STATUS_CAPTURED,
        self::STATUS_SETTLED,
    ];

    protected $fillable = [
        'reservation_id',
        'hotel_id',
        'status',
        'currency',
        'amount',
        'hold_expires_at',
        'provider_customer_ref',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'hold_expires_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class);
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
