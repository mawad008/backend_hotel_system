<?php

namespace App\Domain\IdentityVerification\Models;

use App\Domain\IdentityAccess\Models\User;
use Database\Factories\IdentityVerificationDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6 — an append-only record of every automated and manual decision
 * taken on a verification session (Phase 0 §6.4: `verification_decisions`).
 *
 * Append-only: there is no updated_at. A row is written once and never
 * changed. `decided_by_user_id` is NULL for an automated decision and the
 * acting staff member for a manual one.
 *
 * `reason` is an optional short staff note — it is not a place for PII by
 * policy, and it is length-capped by the Form Request.
 */
class IdentityVerificationDecision extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_AUTOMATED = 'automated';

    public const TYPE_MANUAL = 'manual';

    /**
     * @var array<int, string>
     */
    public const TYPES = [
        self::TYPE_AUTOMATED,
        self::TYPE_MANUAL,
    ];

    public const RESULT_AUTO_APPROVED = 'auto_approved';

    public const RESULT_MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    public const RESULT_RETRY_ALLOWED = 'retry_allowed';

    public const RESULT_RETRY_EXHAUSTED = 'retry_exhausted';

    public const RESULT_STAFF_APPROVED = 'staff_approved';

    public const RESULT_STAFF_REJECTED = 'staff_rejected';

    /**
     * @var array<int, string>
     */
    public const RESULTS = [
        self::RESULT_AUTO_APPROVED,
        self::RESULT_MANUAL_REVIEW_REQUIRED,
        self::RESULT_RETRY_ALLOWED,
        self::RESULT_RETRY_EXHAUSTED,
        self::RESULT_STAFF_APPROVED,
        self::RESULT_STAFF_REJECTED,
    ];

    public const BAND_HIGH = 'high';

    public const BAND_MEDIUM = 'medium';

    public const BAND_LOW = 'low';

    protected $fillable = [
        'session_id',
        'attempt_id',
        'type',
        'result',
        'decided_by_user_id',
        'score',
        'band',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IdentityVerificationSession::class, 'session_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(IdentityVerificationAttempt::class, 'attempt_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    protected static function newFactory(): IdentityVerificationDecisionFactory
    {
        return IdentityVerificationDecisionFactory::new();
    }
}
