<?php

namespace App\Domain\IdentityVerification\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\IdentityVerificationSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 6 — the identity verification lifecycle record for one Reservation
 * (Phase 0 §6.1/§6.4: "Reservation ... has one Verification session"; §10).
 *
 * The persistent status vocabulary lives here; the transition rules between
 * statuses live only in IdentityVerificationStateMachine — never here.
 *
 * Hotel scope is NOT applied via the HotelScoped trait: the Reservation
 * domain resolves scope through the owning record and a Policy, and this
 * follows the same reasoning the Payment domain already uses. `hotel_id` is
 * denormalized from the Reservation for efficient hotel-scoped listing and
 * is never a client-supplied value.
 */
class IdentityVerificationSession extends Model
{
    use HasFactory;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_DOCUMENT_UPLOADED = 'document_uploaded';

    public const STATUS_SELFIE_CAPTURED = 'selfie_captured';

    public const STATUS_MATCHING_IN_PROGRESS = 'matching_in_progress';

    public const STATUS_AUTO_APPROVED = 'auto_approved';

    public const STATUS_PENDING_MANUAL_REVIEW = 'pending_manual_review';

    public const STATUS_STAFF_APPROVED = 'staff_approved';

    public const STATUS_STAFF_REJECTED = 'staff_rejected';

    public const STATUS_RETRY_ALLOWED = 'retry_allowed';

    /**
     * Every approved persistent session status, in lifecycle order
     * (Phase 0 §10). The transition rules between them live only in
     * IdentityVerificationStateMachine.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_NOT_STARTED,
        self::STATUS_DOCUMENT_UPLOADED,
        self::STATUS_SELFIE_CAPTURED,
        self::STATUS_MATCHING_IN_PROGRESS,
        self::STATUS_AUTO_APPROVED,
        self::STATUS_PENDING_MANUAL_REVIEW,
        self::STATUS_STAFF_APPROVED,
        self::STATUS_STAFF_REJECTED,
        self::STATUS_RETRY_ALLOWED,
    ];

    /**
     * Statuses in which the guest's identity is considered verified for the
     * Reservation workflow (Phase 0 §8: DEPOSIT_HELD -> VERIFIED on
     * "verification passes"). An explicit positive list, never a negation.
     *
     * @var array<int, string>
     */
    public const APPROVED_STATUSES = [
        self::STATUS_AUTO_APPROVED,
        self::STATUS_STAFF_APPROVED,
    ];

    protected $fillable = [
        'reservation_id',
        'guest_id',
        'hotel_id',
        'status',
        'provider',
        'attempts',
        'latest_outcome',
        'latest_score',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'latest_score' => 'integer',
            'decided_at' => 'datetime',
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

    public function attemptRecords(): HasMany
    {
        return $this->hasMany(IdentityVerificationAttempt::class, 'session_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(IdentityVerificationDecision::class, 'session_id');
    }

    protected static function newFactory(): IdentityVerificationSessionFactory
    {
        return IdentityVerificationSessionFactory::new();
    }
}
