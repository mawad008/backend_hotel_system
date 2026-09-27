<?php

namespace App\Domain\Review\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Shared\Concerns\HotelScoped;
use App\Support\LocalizedContent;
use Database\Factories\ReviewCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One criterion a guest rates a stay on, defined per hotel from the
 * dashboard (e.g. "النظافة", "الإفطار"). Dynamic — no category, count or name
 * is assumed anywhere in code. `is_active = false` hides it from new reviews
 * only; ratings already given keep referring to it.
 */
class ReviewCategory extends Model
{
    use HasFactory, HotelScoped;

    protected $fillable = [
        'hotel_id',
        'name',
        'name_ar',
        'name_en',
        'description',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(ReviewCategoryRating::class);
    }

    /** The label for the request locale: `name_{locale}` → `name`. */
    public function localizedName(?string $locale = null): string
    {
        return LocalizedContent::resolve(
            ['ar' => $this->name_ar, 'en' => $this->name_en],
            $this->name,
            $locale,
        ) ?? $this->name;
    }

    protected static function newFactory(): ReviewCategoryFactory
    {
        return ReviewCategoryFactory::new();
    }
}
