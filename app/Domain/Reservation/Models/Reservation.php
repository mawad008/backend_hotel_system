<?php

namespace App\Domain\Reservation\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Guest's confirmed or in-progress intent to occupy inventory for a date
 * range (Phase 0 §6.1). Schema and status set only — the state-transition
 * engine, availability/concurrency checks, and cancellation logic are later
 * phases (§6.3, §8, §12).
 */
class Reservation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DEPOSIT_HELD = 'deposit_held';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_IN_STAY = 'in_stay';

    public const STATUS_CHECKOUT_IN_PROGRESS = 'checkout_in_progress';

    public const STATUS_CHECKOUT_BLOCKED = 'checkout_blocked';

    public const STATUS_CHECKED_OUT = 'checked_out';

    public const STATUS_INVOICED = 'invoiced';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Approved Phase 3D decision: every status except CANCELLED holds a
     * claim on inventory for its stored date range (PENDING through
     * CHECKOUT_IN_PROGRESS hold a live, forward-progressing claim;
     * CHECKOUT_BLOCKED/CHECKED_OUT/INVOICED still block their own,
     * already-elapsed range against retroactive double-booking). Deliberately
     * an explicit positive list, not `!== self::STATUS_CANCELLED`, so a
     * future status added to the enum never silently becomes blocking.
     *
     * @var array<int, string>
     */
    public const BLOCKING_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DEPOSIT_HELD,
        self::STATUS_VERIFIED,
        self::STATUS_CHECKED_IN,
        self::STATUS_IN_STAY,
        self::STATUS_CHECKOUT_IN_PROGRESS,
        self::STATUS_CHECKOUT_BLOCKED,
        self::STATUS_CHECKED_OUT,
        self::STATUS_INVOICED,
    ];

    protected $fillable = [
        'hotel_id',
        'room_type_id',
        'room_id',
        'guest_id',
        'check_in',
        'check_out',
        'adults',
        'children',
        'status',
        'price_snapshot',
        'service_fee_amount',
        'currency',
        'is_refundable',
        'free_cancellation_until',
        'room_assigned_by_user_id',
        'room_assigned_at',
        'created_by_staff_id',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * Currency snapshot: a reservation stores the platform currency that
     * applied when it was booked and never follows a later config change.
     */
    protected static function booted(): void
    {
        static::creating(function (self $reservation): void {
            if (blank($reservation->currency)) {
                $reservation->currency = strtoupper((string) config('payment.currency'));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'adults' => 'integer',
            'children' => 'integer',
            'price_snapshot' => 'decimal:2',
            'service_fee_amount' => 'decimal:2',
            'is_refundable' => 'boolean',
            'free_cancellation_until' => 'datetime',
            'room_assigned_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservationGuests(): HasMany
    {
        return $this->hasMany(ReservationGuest::class);
    }

    /**
     * The approved 1:1 Payment record (Phase 0 §6.1, Phase 5A). This is a
     * plain schema relationship only — no payment business logic lives on
     * the Reservation, and ReservationService does not depend on the
     * Payment domain.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    protected static function newFactory(): ReservationFactory
    {
        return ReservationFactory::new();
    }
}
