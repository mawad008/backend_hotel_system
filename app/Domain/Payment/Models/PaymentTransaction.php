<?php

namespace App\Domain\Payment\Models;

use App\Domain\IdentityAccess\Models\User;
use Database\Factories\PaymentTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single provider operation attempt against a Payment (Phase 0 §6.4,
 * Phase 5 plan v2 §7.2). Retry granularity + where the provider reference
 * and idempotency key are stored. Phase 5A: schema/relationship foundation
 * only — no provider calls, no state-transition logic.
 *
 * A transaction's own `status` is independent of the Payment's status and
 * of any webhook event's processing status.
 */
class PaymentTransaction extends Model
{
    use HasFactory;

    public const TYPE_HOLD = 'hold';

    public const TYPE_CANCEL_HOLD = 'cancel_hold';

    public const TYPE_CAPTURE = 'capture';

    public const TYPE_SETTLEMENT = 'settlement';

    public const TYPE_REFUND = 'refund';

    public const TYPE_VERIFY = 'verify';

    /**
     * The approved operation set. capture / settlement / refund are
     * reserved for later phases; the vocabulary is fixed here so the schema
     * is complete.
     *
     * @var array<int, string>
     */
    public const TYPES = [
        self::TYPE_HOLD,
        self::TYPE_CANCEL_HOLD,
        self::TYPE_CAPTURE,
        self::TYPE_SETTLEMENT,
        self::TYPE_REFUND,
        self::TYPE_VERIFY,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /**
     * Transaction types that move money the guest has actually paid — a
     * succeeded row of one of these is what counts toward the folio's
     * `payments_total` (Phase 9 review fix). A `hold` is only an
     * authorization and never counts.
     *
     * @var array<int, string>
     */
    public const COLLECTED_TYPES = [
        self::TYPE_CAPTURE,
        self::TYPE_SETTLEMENT,
    ];

    protected $fillable = [
        'payment_id',
        'type',
        'status',
        'amount',
        'currency',
        'idempotency_key',
        'provider',
        'provider_reference',
        'requested_by_user_id',
        'metadata',
    ];

    /**
     * Currency snapshot: each transaction permanently records the currency
     * of its payment (itself the reservation's snapshot) at creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            if (blank($transaction->currency)) {
                $transaction->currency = Payment::query()->whereKey($transaction->payment_id)->value('currency')
                    ?: strtoupper((string) config('payment.currency'));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The staff member who triggered this attempt, if any — NULL for a
     * system/provider-originated operation.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected static function newFactory(): PaymentTransactionFactory
    {
        return PaymentTransactionFactory::new();
    }
}
