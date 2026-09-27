<?php

namespace App\Domain\Loyalty\Models;

use App\Domain\Reservation\Models\Guest;
use Database\Factories\LoyaltyAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 10 — the loyalty account for one Guest (Phase 0 §6.4, §13, R43).
 * One per Guest, group-wide.
 *
 * `points_balance` is a cache; the `transactions` ledger is authoritative
 * (guardrail #8). The balance is only ever changed alongside a ledger row,
 * inside a locked transaction (LoyaltyService).
 */
class LoyaltyAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'points_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'points_balance' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    protected static function newFactory(): LoyaltyAccountFactory
    {
        return LoyaltyAccountFactory::new();
    }
}
