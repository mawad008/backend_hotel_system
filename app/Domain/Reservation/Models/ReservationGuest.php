<?php

namespace App\Domain\Reservation\Models;

use Database\Factories\ReservationGuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assigns a Guest to a Reservation. Deliberately minimal for Phase 3A: no
 * enforcement beyond the DB-level uniqueness of (reservation_id, guest_id).
 */
class ReservationGuest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'guest_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    protected static function newFactory(): ReservationGuestFactory
    {
        return ReservationGuestFactory::new();
    }
}
