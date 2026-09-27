<?php

namespace App\Domain\Inventory\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Concerns\HasRoomMedia;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Shared\Concerns\HotelScoped;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory, HasRoomMedia, HotelScoped;

    protected $fillable = [
        'hotel_id',
        'room_type_id',
        'room_number',
        'status',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    protected static function newFactory(): RoomFactory
    {
        return RoomFactory::new();
    }
}
