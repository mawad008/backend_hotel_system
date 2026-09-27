<?php

namespace App\Domain\Discovery\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\Guest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hotel a guest saved as a favourite. Owned by the guest; the pair is
 * unique, so saving twice is a no-op.
 */
class GuestFavoriteHotel extends Model
{
    protected $fillable = [
        'guest_id',
        'hotel_id',
    ];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
