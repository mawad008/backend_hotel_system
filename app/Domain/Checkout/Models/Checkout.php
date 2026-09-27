<?php

namespace App\Domain\Checkout\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\CheckoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9 — the checkout process record for one reservation (Phase 0 §12).
 *
 * The persistent status vocabulary lives here; the transition rules live
 * only in CheckoutStateMachine. Not HotelScoped via the trait — like
 * Payment / ServiceOrder, scope is resolved through the owning Reservation
 * and a Policy. `hotel_id` is denormalized from the reservation and never a
 * client value.
 */
class Checkout extends Model
{
    use HasFactory;

    /** Checkout started; reservation is CHECKOUT_IN_PROGRESS. */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /** A required final settlement returned pending from the provider. */
    public const STATUS_AWAITING_SETTLEMENT = 'awaiting_settlement';

    /** A required final settlement failed/cancelled/expired — retryable. */
    public const STATUS_SETTLEMENT_FAILED = 'settlement_failed';

    /** Reservation is INVOICED and the invoice is issued. Terminal. */
    public const STATUS_COMPLETED = 'completed';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_IN_PROGRESS,
        self::STATUS_AWAITING_SETTLEMENT,
        self::STATUS_SETTLEMENT_FAILED,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'reservation_id',
        'hotel_id',
        'status',
        'charges_total',
        'payments_total',
        'outstanding_total',
        'currency',
        'settlement_transaction_id',
        'started_at',
        'completed_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'charges_total' => 'decimal:2',
            'payments_total' => 'decimal:2',
            'outstanding_total' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function settlementTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'settlement_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function newFactory(): CheckoutFactory
    {
        return CheckoutFactory::new();
    }
}
