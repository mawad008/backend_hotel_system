<?php

namespace App\Domain\Location\Models;

use App\Domain\HotelGroup\Models\Hotel;
use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global reference data — NOT hotel-scoped. A Country groups Cities and is
 * referenced by Hotels (and, later, discovery/search/reporting). It carries
 * no business rules of its own beyond an active flag.
 */
class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'name_en',
        'name_ar',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    protected static function newFactory(): CountryFactory
    {
        return CountryFactory::new();
    }
}
