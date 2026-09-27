<?php

namespace App\Domain\Loyalty\Models;

use App\Domain\HotelGroup\Models\HotelGroup;
use Database\Factories\LoyaltyRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10 — the configurable loyalty economics for one Hotel Group
 * (Phase 0 §6.4, §13). No values are seeded or defaulted — a Group Owner
 * activates it explicitly.
 */
class LoyaltyRule extends Model
{
    use HasFactory;

    /** The only earn source implemented in the MVP. */
    public const SOURCE_RESERVATION = 'reservation';

    protected $fillable = [
        'hotel_group_id',
        'is_active',
        'earn_points_per_currency',
        'redeem_currency_per_point',
        'max_redeem_points',
        'eligible_source_types',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'max_redeem_points' => 'integer',
            'earn_points_per_currency' => 'decimal:4',
            'redeem_currency_per_point' => 'decimal:4',
            'eligible_source_types' => 'array',
        ];
    }

    public function hotelGroup(): BelongsTo
    {
        return $this->belongsTo(HotelGroup::class);
    }

    /**
     * The rule is usable for accrual only when active AND an earn rate is
     * configured (Phase 0 §13: no rate is ever invented).
     */
    public function canEarn(): bool
    {
        return $this->is_active && $this->earn_points_per_currency !== null;
    }

    /**
     * The rule is usable for redemption only when active AND a point value
     * is configured.
     */
    public function canRedeem(): bool
    {
        return $this->is_active && $this->redeem_currency_per_point !== null;
    }

    /**
     * @return array<int, string>
     */
    public function sourceTypes(): array
    {
        $types = $this->eligible_source_types;

        return is_array($types) && $types !== [] ? $types : [self::SOURCE_RESERVATION];
    }

    protected static function newFactory(): LoyaltyRuleFactory
    {
        return LoyaltyRuleFactory::new();
    }
}
