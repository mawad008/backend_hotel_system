<?php

namespace App\Domain\Location\Models;

use App\Domain\HotelGroup\Models\Hotel;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global reference data — NOT hotel-scoped. A City always belongs to a
 * Country; that relationship is enforced by the DB, the services and the
 * hotel form requests (a hotel's City must belong to its Country).
 */
class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_id',
        'name_en',
        'name_ar',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'country_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    protected static function newFactory(): CityFactory
    {
        return CityFactory::new();
    }
}
