<?php

namespace App\Domain\Support\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Database\Factories\ProblemReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's in-stay problem report (mobile/Design/13 · Report a
 * problem.png) — category + urgency + optional free-text note, tied to the
 * reservation it was reported during. Many per reservation (unlike Review,
 * which is one-per-stay).
 */
class ProblemReport extends Model
{
    use HasFactory;

    public const CATEGORY_AC_HEATING = 'ac_heating';

    public const CATEGORY_PLUMBING_WATER = 'plumbing_water';

    public const CATEGORY_ELECTRICITY_LIGHTING = 'electricity_lighting';

    public const CATEGORY_ROOM_CLEANLINESS = 'room_cleanliness';

    public const CATEGORY_INTERNET_WIFI = 'internet_wifi';

    public const CATEGORY_NOISE_DISTURBANCE = 'noise_disturbance';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_AC_HEATING,
        self::CATEGORY_PLUMBING_WATER,
        self::CATEGORY_ELECTRICITY_LIGHTING,
        self::CATEGORY_ROOM_CLEANLINESS,
        self::CATEGORY_INTERNET_WIFI,
        self::CATEGORY_NOISE_DISTURBANCE,
    ];

    public const URGENCY_NORMAL = 'normal';

    public const URGENCY_IMPORTANT = 'important';

    public const URGENCY_URGENT = 'urgent';

    /** @var list<string> */
    public const URGENCIES = [
        self::URGENCY_NORMAL,
        self::URGENCY_IMPORTANT,
        self::URGENCY_URGENT,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
    ];

    protected $fillable = [
        'reservation_id',
        'guest_id',
        'hotel_id',
        'category',
        'urgency',
        'notes',
        'status',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    protected static function newFactory(): ProblemReportFactory
    {
        return ProblemReportFactory::new();
    }
}
