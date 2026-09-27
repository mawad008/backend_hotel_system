<?php

namespace App\Domain\DigitalAccess\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\AccessGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7 — the digital access lifecycle record for one Reservation
 * (Phase 0 §6.1: "Reservation ... has one Access grant"; §6.4: `access_grants`;
 * §11 Digital Access State Machine).
 *
 * The persistent status vocabulary lives here; the transition rules between
 * statuses live only in DigitalAccessStateMachine — never here.
 *
 * Hotel scope is NOT applied via the HotelScoped trait: the Reservation
 * domain resolves scope through the owning record and a Policy, and this
 * follows the same reasoning the Payment / Identity Verification domains
 * already use. `hotel_id` / `guest_id` are denormalized from the Reservation
 * and are never client-supplied values.
 *
 * SECURITY: `credential` (the app-delivered PIN, §11 pin_code mode) is
 * ENCRYPTED at rest and `$hidden` from array/JSON serialization. It is
 * exposed to an authorized client ONLY while the grant is ACTIVE, through
 * AccessGrantResource, and is nulled the moment the grant is revoked or
 * expires. It never reaches an audit row or a log line.
 */
class AccessGrant extends Model
{
    use HasFactory;

    /** Business lifecycle (§11): NOT_ISSUED -> ISSUED(=ACTIVE) -> {EXPIRED | REVOKED}. */
    public const STATUS_NOT_ISSUED = 'not_issued';

    /** Architecture-derived pending state for the staged provider call (mirrors Payment HOLD_REQUESTED). */
    public const STATUS_ISSUE_REQUESTED = 'issue_requested';

    /** §11 "ISSUED (active)". */
    public const STATUS_ACTIVE = 'active';

    /** Architecture-derived recoverable failure state (§15 simulates "failure"; mirrors Payment HOLD_FAILED). */
    public const STATUS_FAILED = 'failed';

    /** Architecture-derived pending state for the staged revoke provider call. */
    public const STATUS_REVOKE_REQUESTED = 'revoke_requested';

    /** §11 terminal. */
    public const STATUS_REVOKED = 'revoked';

    /** §11 terminal. */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Every approved persistent status, in lifecycle order. The transition
     * rules between them live only in DigitalAccessStateMachine.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_NOT_ISSUED,
        self::STATUS_ISSUE_REQUESTED,
        self::STATUS_ACTIVE,
        self::STATUS_FAILED,
        self::STATUS_REVOKE_REQUESTED,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
    ];

    public const MODE_PIN_CODE = 'pin_code';

    public const MODE_SMART_LOCK = 'smart_lock';

    /**
     * @var array<int, string>
     */
    public const MODES = [
        self::MODE_PIN_CODE,
        self::MODE_SMART_LOCK,
    ];

    protected $fillable = [
        'reservation_id',
        'hotel_id',
        'guest_id',
        'status',
        'access_mode',
        'provider',
        'provider_reference',
        'credential',
        'idempotency_key',
        'issued_at',
        'activated_at',
        'expires_at',
        'revoked_at',
        'revocation_reason',
        'failure_reason',
        'metadata',
    ];

    protected $hidden = [
        'credential',
        'idempotency_key',
        'provider_reference',
    ];

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted',
            'metadata' => 'array',
            'issued_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    protected static function newFactory(): AccessGrantFactory
    {
        return AccessGrantFactory::new();
    }
}
