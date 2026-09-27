<?php

namespace App\Domain\Checkout\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 9 — the final invoice for one reservation (Phase 0 §6.4, §12).
 *
 * One per reservation (unique `reservation_id`). Totals are copied from the
 * authoritative final folio at checkout time; the client never supplies
 * them. No tax / discount / fee fields — none are approved.
 */
class Invoice extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ISSUED,
    ];

    protected $fillable = [
        'reservation_id',
        'hotel_id',
        'invoice_number',
        'status',
        'currency',
        'subtotal',
        'payments_total',
        'outstanding_total',
        'issued_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'payments_total' => 'decimal:2',
            'outstanding_total' => 'decimal:2',
            'issued_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
