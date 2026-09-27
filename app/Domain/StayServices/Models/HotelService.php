<?php

namespace App\Domain\StayServices\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Shared\Concerns\HotelScoped;
use Database\Factories\HotelServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8 — something a guest can request/consume during a stay
 * (Phase 0 §6.4 `hotel_services`, R17/R20).
 *
 * Simple pricing only (R54): a single `price`. No taxes, discounts,
 * commissions, tiers, or inventory. Hotel-scoped via the shared trait.
 */
class HotelService extends Model
{
    use HasFactory, HotelScoped;

    protected $table = 'hotel_services';

    protected $fillable = [
        'hotel_id',
        'service_category_id',
        'name',
        'description',
        'price',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'service_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ServiceReview::class, 'service_id');
    }

    protected static function newFactory(): HotelServiceFactory
    {
        return HotelServiceFactory::new();
    }
}
