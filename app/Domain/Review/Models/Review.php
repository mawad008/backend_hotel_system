<?php

namespace App\Domain\Review\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A guest's post-stay review of one completed reservation
 * (mobile/docs/mobile-phase-10-loyalty-reviews.md). One per reservation —
 * enforced by the `reviews.reservation_id` UNIQUE constraint, not just the
 * service layer.
 *
 * Only `published` reviews are ever shown to another guest; `pending` /
 * `rejected` are visible to their own author and to staff only.
 */
class Review extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'reservation_id',
        'guest_id',
        'hotel_id',
        'rating',
        'text',
        'status',
        'moderated_by_user_id',
        'moderated_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_user_id');
    }

    /** Per-category ratings (dynamic, per-hotel [ReviewCategory]s). */
    public function categoryRatings(): HasMany
    {
        return $this->hasMany(ReviewCategoryRating::class)->orderBy('id');
    }

    protected static function newFactory(): ReviewFactory
    {
        return ReviewFactory::new();
    }
}
