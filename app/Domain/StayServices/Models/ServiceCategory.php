<?php

namespace App\Domain\StayServices\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Shared\Concerns\HotelScoped;
use Database\Factories\ServiceCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8 — an optional, hotel-scoped grouping for a hotel's service
 * catalog (Phase 0 §6.1). Carries no business rules of its own; none are
 * seeded.
 *
 * Uses the shared HotelScoped trait: `hotel_id` is the scope column and a
 * Group Owner bypasses it, exactly as RoomType does.
 */
class ServiceCategory extends Model
{
    use HasFactory, HotelScoped;

    protected $fillable = [
        'hotel_id',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(HotelService::class);
    }

    protected static function newFactory(): ServiceCategoryFactory
    {
        return ServiceCategoryFactory::new();
    }
}
