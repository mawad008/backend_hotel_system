<?php

namespace App\Domain\StayServices\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\ServiceOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Phase 8 — a service requested/ordered for one reservation (Phase 0 §6.4
 * `service_orders`, R17/R20).
 *
 * Historical pricing lives on the row (`unit_price_snapshot` /
 * `currency_snapshot` / `total_amount`); it is never recomputed from the
 * service's current price. The persistent status vocabulary lives here; the
 * transition rules between statuses live only in ServiceOrderStateMachine.
 *
 * Not HotelScoped via the trait — like Payment / AccessGrant, scope is
 * resolved through the owning Reservation and a Policy. `hotel_id` is
 * denormalized from the reservation and never a client value.
 */
class ServiceOrder extends Model
{
    use HasFactory;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Every approved persistent status, in lifecycle order. Transition
     * rules between them live only in ServiceOrderStateMachine.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_CONFIRMED,
        self::STATUS_FULFILLED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'reservation_id',
        'hotel_id',
        'service_id',
        'quantity',
        'unit_price_snapshot',
        'currency_snapshot',
        'total_amount',
        'status',
        'notes',
        'requested_by_user_id',
        'requested_at',
        'confirmed_at',
        'fulfilled_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_snapshot' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(HotelService::class, 'service_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(ServiceReview::class);
    }

    protected static function newFactory(): ServiceOrderFactory
    {
        return ServiceOrderFactory::new();
    }
}
