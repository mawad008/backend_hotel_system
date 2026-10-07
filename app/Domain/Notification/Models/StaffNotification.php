<?php

namespace App\Domain\Notification\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Enums\StaffNotificationType;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dashboard-inbox entry for one staff user. Recipient-scoped: a user
 * only ever reads or marks their own rows.
 */
class StaffNotification extends Model
{
    protected $fillable = [
        'user_id',
        'hotel_id',
        'reservation_id',
        'audit_log_id',
        'type',
        'context',
        'read_at',
    ];

    protected $hidden = [
        'audit_log_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => StaffNotificationType::class,
            'context' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
