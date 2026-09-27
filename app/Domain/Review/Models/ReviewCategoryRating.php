<?php

namespace App\Domain\Review\Models;

use App\Support\LocalizedContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's 1-5 rating of one [ReviewCategory] within one [Review].
 *
 * Holds both the category reference (the stable identity averages group by)
 * and a snapshot of the category's labels at rating time
 * (`category_name*`), so the review stays readable exactly as rated even if
 * the category is later renamed or deactivated.
 */
class ReviewCategoryRating extends Model
{
    protected $fillable = [
        'review_id',
        'review_category_id',
        'rating',
        'category_name',
        'category_name_ar',
        'category_name_en',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReviewCategory::class, 'review_category_id');
    }

    /** The snapshot label for the request locale. */
    public function localizedSnapshotName(?string $locale = null): string
    {
        return LocalizedContent::resolve(
            ['ar' => $this->category_name_ar, 'en' => $this->category_name_en],
            $this->category_name,
            $locale,
        ) ?? $this->category_name;
    }
}
