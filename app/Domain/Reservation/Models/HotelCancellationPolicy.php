<?php

namespace App\Domain\Reservation\Models;

use App\Domain\HotelGroup\Models\Hotel;
use Database\Factories\HotelCancellationPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Field-shape-only cancellation config for a Hotel (Phase 0 §12). No default
 * values are populated anywhere — the absence of a policy row must never be
 * interpreted as free cancellation; that reading is left to whichever future
 * phase implements actual cancellation behavior.
 */
class HotelCancellationPolicy extends Model
{
    use HasFactory;

    public const PENALTY_PERCENTAGE = 'percentage';

    public const PENALTY_FLAT = 'flat';

    public const PENALTY_NONE = 'none';

    protected $fillable = [
        'hotel_id',
        'notice_period_hours',
        'penalty_type',
        'penalty_value',
    ];

    protected function casts(): array
    {
        return [
            'notice_period_hours' => 'integer',
            'penalty_value' => 'decimal:2',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    protected static function newFactory(): HotelCancellationPolicyFactory
    {
        return HotelCancellationPolicyFactory::new();
    }
}
