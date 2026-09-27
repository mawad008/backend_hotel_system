<?php

namespace App\Domain\Inventory\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Concerns\HasRoomMedia;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Shared\Concerns\HotelScoped;
use Database\Factories\RoomTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomType extends Model
{
    use HasFactory, HasRoomMedia, HotelScoped;

    protected $fillable = [
        'hotel_id',
        'name',
        'base_price',
        'capacity',
        'amenities',
        'description',
        'is_active',
        'bed_type_i18n',
        'view_i18n',
        'area_sqm',
        'breakfast_included',
        'refundable',
        'custom_specs',
        'tag_i18n',
        'inclusions_i18n',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'amenities' => 'array',
            'is_active' => 'boolean',
            'bed_type_i18n' => 'array',
            'view_i18n' => 'array',
            'area_sqm' => 'integer',
            'breakfast_included' => 'boolean',
            'refundable' => 'boolean',
            'custom_specs' => 'array',
            'tag_i18n' => 'array',
            'inclusions_i18n' => 'array',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    protected static function newFactory(): RoomTypeFactory
    {
        return RoomTypeFactory::new();
    }
}
