<?php

namespace App\Domain\Reservation\Models;

use App\Domain\Loyalty\Models\LoyaltyAccount;
use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The person-account making bookings — distinct from staff `Users`
 * (Phase 0 §6.1). Authenticates via phone + OTP through its own Sanctum
 * guard (`auth:guest`, provider `guests`); it has no password. Not
 * hotel-scoped — a Guest may have reservations across multiple hotels.
 * A group-wide loyalty account is created on first use, not at guest creation.
 */
class Guest extends Authenticatable
{
    /** @use HasFactory<GuestFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone_verified_at',
        'profile_completed_at',
        'preferences',
        'identity_retention',
        'data_deletion_requested_at',
    ];

    /**
     * Identity-image retention choice (`PROFILE_Privacy`). Default: the
     * images go after checkout (the hotel's stay copy is purged after
     * `verification.retention_days`); `keep_for_future` keeps the guest's
     * latest approved images for their next booking.
     */
    public const IDENTITY_DELETE_AFTER_CHECKOUT = 'delete_after_checkout';

    public const IDENTITY_KEEP_FOR_FUTURE = 'keep_for_future';

    public const IDENTITY_RETENTION_OPTIONS = [self::IDENTITY_DELETE_AFTER_CHECKOUT, self::IDENTITY_KEEP_FOR_FUTURE];

    /**
     * Stay/communication preferences and their defaults when the guest has
     * not chosen yet (`PROFILE_Preferences`).
     */
    public const PREFERENCE_DEFAULTS = [
        'high_floor' => false,
        'extra_pillows' => false,
        'notifications_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'preferences' => 'array',
            'data_deletion_requested_at' => 'datetime',
        ];
    }

    /**
     * The guest's preferences with defaults filled in — always the full,
     * known key set, never an unknown client key.
     *
     * @return array{high_floor: bool, extra_pillows: bool, notifications_enabled: bool}
     */
    public function resolvedPreferences(): array
    {
        $stored = is_array($this->preferences) ? $this->preferences : [];

        return array_map(
            'boolval',
            array_intersect_key($stored, self::PREFERENCE_DEFAULTS) + self::PREFERENCE_DEFAULTS,
        );
    }

    /**
     * The Guest has no password — authentication is phone + OTP only. An
     * empty string ensures any accidental credential guard comparison
     * fails closed rather than throwing.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * A guest may act (create reservations, pay, verify identity) the
     * moment their phone is proven; the name/email step can follow. This
     * flag only gates UI copy, never authorization.
     */
    public function isProfileComplete(): bool
    {
        return $this->profile_completed_at !== null
            && filled($this->name)
            && filled($this->email);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(LoyaltyAccount::class);
    }

    protected static function newFactory(): GuestFactory
    {
        return GuestFactory::new();
    }
}
