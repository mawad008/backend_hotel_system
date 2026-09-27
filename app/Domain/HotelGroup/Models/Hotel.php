<?php

namespace App\Domain\HotelGroup\Models;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use App\Domain\Reservation\Models\HotelCancellationPolicy;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Review\Models\Review;
use App\Domain\Review\Models\ReviewCategory;
use App\Domain\Shared\Concerns\HotelScoped;
use Database\Factories\HotelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Hotel extends Model
{
    use HasFactory, HotelScoped;

    /** Who may check a guest in (approved 2026-09-26, per hotel). */
    public const CHECK_IN_SELF = 'self';

    public const CHECK_IN_RECEPTION = 'reception';

    public const CHECK_IN_BOTH = 'both';

    public const CHECK_IN_MODES = [self::CHECK_IN_SELF, self::CHECK_IN_RECEPTION, self::CHECK_IN_BOTH];

    /**
     * The pre-booking deposit hold for a stay priced `$price`: the hotel's
     * `deposit_percentage` of it, to the halala (bcmath — no float drift).
     * Null when the hotel has no deposit rule.
     */
    public function depositFor(string $price): ?string
    {
        if ($this->deposit_percentage === null) {
            return null;
        }

        return bcdiv(bcmul($price, (string) $this->deposit_percentage, 4), '100', 2);
    }

    public const SERVICE_FEE_FIXED = 'fixed';

    public const SERVICE_FEE_PERCENTAGE = 'percentage';

    public const SERVICE_FEE_TYPES = [self::SERVICE_FEE_FIXED, self::SERVICE_FEE_PERCENTAGE];

    /**
     * The booking service fee ("رسوم الخدمة") for a stay priced `$stayPrice`:
     * a fixed amount per booking, or a percentage of the stay price, to the
     * halala. "0.00" when the hotel has the fee switched off.
     */
    public function serviceFeeFor(string $stayPrice): string
    {
        if (! $this->service_fee_enabled || $this->service_fee_value === null) {
            return '0.00';
        }

        $value = (string) $this->service_fee_value;

        return $this->service_fee_type === self::SERVICE_FEE_PERCENTAGE
            ? bcdiv(bcmul($stayPrice, $value, 4), '100', 2)
            : bcadd($value, '0', 2);
    }

    public function allowsSelfCheckIn(): bool
    {
        return in_array($this->check_in_mode ?? self::CHECK_IN_BOTH, [self::CHECK_IN_SELF, self::CHECK_IN_BOTH], true);
    }

    public function allowsReceptionCheckIn(): bool
    {
        return in_array($this->check_in_mode ?? self::CHECK_IN_BOTH, [self::CHECK_IN_RECEPTION, self::CHECK_IN_BOTH], true);
    }

    protected $fillable = [
        'hotel_group_id',
        'name',
        'name_i18n',
        'tagline_i18n',
        'description_i18n',
        'star_rating',
        'deposit_percentage',
        'prices_include_taxes',
        'service_fee_enabled',
        'service_fee_type',
        'service_fee_value',
        'slug',
        'country_id',
        'city_id',
        'country',
        'city',
        'timezone',
        'is_active',
        'meta_title_i18n',
        'meta_description_i18n',
        'seo_indexable',
        'check_in_time',
        'reception_phone',
        'check_in_mode',
        'check_out_time',
        'suitable_for_i18n',
        'location_note_i18n',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'name_i18n' => 'array',
            'tagline_i18n' => 'array',
            'description_i18n' => 'array',
            'star_rating' => 'integer',
            'deposit_percentage' => 'decimal:2',
            'prices_include_taxes' => 'boolean',
            'service_fee_enabled' => 'boolean',
            'service_fee_value' => 'decimal:2',
            'meta_title_i18n' => 'array',
            'meta_description_i18n' => 'array',
            'seo_indexable' => 'boolean',
            'suitable_for_i18n' => 'array',
            'location_note_i18n' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function hotelGroup(): BelongsTo
    {
        return $this->belongsTo(HotelGroup::class);
    }

    /**
     * Normalized location references onto the global Country/City master
     * data. Nullable for legacy rows created before normalization; new and
     * updated hotels are validated so the City belongs to the Country.
     *
     * Named `*Ref` because `country` / `city` are still real string columns
     * on this table (the legacy free-text values, kept in sync) — so the
     * bare `$hotel->country` accessor must keep returning that string.
     */
    public function countryRef(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function cityRef(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_hotel_access')->withTimestamps();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** The dashboard-managed criteria guests rate this hotel on. */
    public function reviewCategories(): HasMany
    {
        return $this->hasMany(ReviewCategory::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Guest Hotel Detail "why choose" feature cards, in display order. */
    public function highlights(): HasMany
    {
        return $this->hasMany(HotelHighlight::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Guest Hotel Detail location section's nearby places, in display order. */
    public function nearbyPlaces(): HasMany
    {
        return $this->hasMany(HotelNearbyPlace::class)->orderBy('sort_order')->orderBy('id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    /**
     * Facilities selected from the global Facility catalog — replaces the
     * old free-text `amenities` JSON column. See `facility_hotel`.
     */
    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'facility_hotel')
            ->withTimestamps()
            ->orderBy('facilities.sort_order');
    }

    /**
     * All media rows, in display order. Filtered per-collection by the
     * dedicated accessors below.
     */
    public function media(): HasMany
    {
        return $this->hasMany(HotelMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function logo(): HasOne
    {
        return $this->hasOne(HotelMedia::class)->where('collection', HotelMedia::COLLECTION_LOGO);
    }

    public function cover(): HasOne
    {
        return $this->hasOne(HotelMedia::class)->where('collection', HotelMedia::COLLECTION_COVER);
    }

    public function galleryMedia(): HasMany
    {
        return $this->hasMany(HotelMedia::class)
            ->where('collection', HotelMedia::COLLECTION_GALLERY)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Cancellation policy cardinality is intentionally left open pending
     * an explicit business decision.
     */
    public function cancellationPolicies(): HasMany
    {
        return $this->hasMany(HotelCancellationPolicy::class);
    }

    /**
     * A Hotel row *is* the hotel-scope boundary, so it is scoped by its
     * own primary key rather than a `hotel_id` foreign key.
     */
    public function hotelScopeColumn(): string
    {
        return 'id';
    }

    protected static function newFactory(): HotelFactory
    {
        return HotelFactory::new();
    }
}
