<?php

namespace App\Domain\Reservation\Models;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\FolioCharge;
use Database\Factories\ReservationExtensionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extend Stay — the audit ledger row for one checkout-date extension. Not a
 * Payment and not itself a folio charge (it points to one via
 * [folio_charge_id]) — this is the record of *why* the reservation's
 * check_out/price_snapshot changed and *why* a `stay_extension` folio charge
 * exists (see [App\Domain\Reservation\Services\ReservationExtensionService]).
 */
class ReservationExtension extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'hotel_id',
        'previous_check_out',
        'new_check_out',
        'nights_added',
        'unit_price',
        'amount',
        'currency',
        'folio_charge_id',
        'idempotency_key',
        'created_by_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'previous_check_out' => 'date',
            'new_check_out' => 'date',
            'nights_added' => 'integer',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function folioCharge(): BelongsTo
    {
        return $this->belongsTo(FolioCharge::class, 'folio_charge_id');
    }

    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_staff_id');
    }

    protected static function newFactory(): ReservationExtensionFactory
    {
        return ReservationExtensionFactory::new();
    }
}
