<?php

namespace App\Domain\Discovery\Models;

use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One room type a guest saved as a favourite. Owned by the guest; the pair is
 * unique, so saving twice is a no-op.
 */
class GuestFavoriteRoomType extends Model
{
    protected $fillable = [
        'guest_id',
        'room_type_id',
    ];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
