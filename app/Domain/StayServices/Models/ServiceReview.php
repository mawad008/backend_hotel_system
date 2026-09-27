<?php

namespace App\Domain\StayServices\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;
use Database\Factories\ServiceReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's review of one fulfilled service order — independent of the
 * hotel-level `Review`. One per service order, enforced by the
 * `service_reviews.service_order_id` UNIQUE constraint.
 *
 * Only `published` reviews contribute to a service's aggregate rating
 * (mirrors `Review::STATUS_PUBLISHED`); `pending`/`rejected` are visible to
 * their own author and to staff only.
 */
class ServiceReview extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'service_order_id',
        'guest_id',
        'hotel_id',
        'service_id',
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

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(HotelService::class, 'service_id');
    }

    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_user_id');
    }

    protected static function newFactory(): ServiceReviewFactory
    {
        return ServiceReviewFactory::new();
    }
}
