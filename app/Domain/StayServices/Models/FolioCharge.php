<?php

namespace App\Domain\StayServices\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\FolioChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8 — a financial amount attached to a reservation's folio
 * (Phase 0 §6.4 `guest_charges`, R26-R31).
 *
 * FolioCharge = what the guest owes / was charged for. It is NOT a Payment
 * (money movement). No card data / provider secret is ever stored.
 *
 * `status` is the CHARGE lifecycle and is independent of any payment
 * status — a charge is never implicitly "paid" just because it exists.
 */
class FolioCharge extends Model
{
    use HasFactory;

    public const SOURCE_SERVICE_ORDER = 'service_order';

    /**
     * The reservation accommodation charge (Phase 9). Amount is
     * `reservation.price_snapshot` used exactly — no nights maths, no tax,
     * no second pricing calculation. `source_id` = the reservation id, so
     * the `(source_type, source_id)` UNIQUE guarantees exactly one per
     * reservation and a checkout retry never duplicates it.
     */
    public const SOURCE_ACCOMMODATION = 'accommodation';

    /**
     * Extend Stay — the incremental accommodation amount for one checkout
     * extension (`nights_added * room_types.base_price`). `source_id` = the
     * `reservation_extensions` row id, NOT the reservation id: a reservation
     * may be extended more than once, each with its own charge, unlike the
     * single [SOURCE_ACCOMMODATION] charge per reservation.
     */
    public const SOURCE_STAY_EXTENSION = 'stay_extension';

    /**
     * A loyalty redemption discount — a NEGATIVE line (`source_id` = the
     * reservation id, one per booking), capped at the accommodation total.
     */
    public const SOURCE_LOYALTY_REDEMPTION = 'loyalty_redemption';

    /**
     * The hotel's booking service fee ("رسوم الخدمة") — `source_id` = the
     * reservation id, one per booking; amount = the fee snapshotted on the
     * reservation at booking time.
     */
    public const SOURCE_SERVICE_FEE = 'service_fee';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_POSTED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Charge statuses that represent a live amount the guest owes — the
     * only ones that count toward the folio's charges total.
     *
     * @var array<int, string>
     */
    public const OWED_STATUSES = [
        self::STATUS_POSTED,
    ];

    protected $fillable = [
        'reservation_id',
        'hotel_id',
        'source_type',
        'source_id',
        'description',
        'quantity',
        'unit_amount',
        'total_amount',
        'currency',
        'status',
        'charged_at',
        'cancelled_at',
        'created_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'charged_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function newFactory(): FolioChargeFactory
    {
        return FolioChargeFactory::new();
    }
}
