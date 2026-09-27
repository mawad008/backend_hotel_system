<?php

namespace App\Domain\HotelGroup\Models;

use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The hotel facility/amenity catalog — global reference data (not
 * hotel-group- or hotel-scoped), analogous to Country/City. A Hotel selects
 * from this catalog via `facility_hotel` rather than duplicating facility
 * strings per hotel.
 */
class Facility extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name_i18n',
        'description_i18n',
        'icon',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'name_i18n' => 'array',
            'description_i18n' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class, 'facility_hotel')->withTimestamps();
    }

    protected static function newFactory(): FacilityFactory
    {
        return FacilityFactory::new();
    }
}
