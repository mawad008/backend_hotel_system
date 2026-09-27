<?php

namespace App\Domain\Loyalty\Models;

use App\Domain\IdentityAccess\Models\User;
use Database\Factories\LoyaltyTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10 — one append-only entry in the loyalty ledger (Phase 0 §6.4,
 * §13). The single source of truth for a guest's balance.
 *
 * `points` is a signed delta. Append-only — no `updated_at`.
 */
class LoyaltyTransaction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /** A completed booking accrued points. */
    public const TYPE_EARN = 'earn';

    /** Points were spent against an eligible booking. */
    public const TYPE_REDEEM = 'redeem';

    /** Reserved: undo a prior entry (no MVP endpoint). */
    public const TYPE_REVERSE = 'reverse';

    /** Reserved: manual staff correction (no MVP endpoint). */
    public const TYPE_ADJUST = 'adjust';

    /** Reserved in the enum only — NEVER written (R51: no expiration). */
    public const TYPE_EXPIRE = 'expire';

    /**
     * @var array<int, string>
     */
    public const TYPES = [
        self::TYPE_EARN,
        self::TYPE_REDEEM,
        self::TYPE_REVERSE,
        self::TYPE_ADJUST,
        self::TYPE_EXPIRE,
    ];

    public const SOURCE_RESERVATION = 'reservation';

    protected $fillable = [
        'loyalty_account_id',
        'type',
        'points',
        'source_type',
        'source_id',
        'reverses_transaction_id',
        'description',
        'created_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function newFactory(): LoyaltyTransactionFactory
    {
        return LoyaltyTransactionFactory::new();
    }
}
