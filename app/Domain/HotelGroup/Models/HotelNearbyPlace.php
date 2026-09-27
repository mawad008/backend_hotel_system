<?php

namespace App\Domain\HotelGroup\Models;

use App\Domain\HotelGroup\Enums\NearbyPlaceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A nearby place shown in the guest Hotel Detail's location section
 * ("مطار جدة · 25 دقيقة") — admin-managed per hotel. Inactive rows stay in
 * the dashboard but are not sent to guests.
 */
class HotelNearbyPlace extends Model
{
    /** Accepted `distance_unit` values. */
    public const DISTANCE_UNITS = ['m', 'km'];

    protected $fillable = [
        'hotel_id',
        'icon',
        'category',
        'name_i18n',
        'travel_minutes',
        'distance',
        'distance_unit',
        'latitude',
        'longitude',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'category' => NearbyPlaceCategory::class,
            'name_i18n' => 'array',
            'travel_minutes' => 'integer',
            'distance' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
