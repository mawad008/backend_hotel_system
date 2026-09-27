<?php

namespace App\Http\Resources\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Support\LocalizedContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Hotel
 *
 * Anonymous guest view of a hotel (Slice 1 + booking discovery enrichment).
 * Deliberately narrower than the staff HotelResource: no `hotel_group_id`,
 * no `is_active` (always true here), no raw i18n maps.
 *
 * `name` / `tagline` / `description` are resolved to the request locale
 * (`X-Locale` header / `?lang` / `Accept-Language`, see SetLocale) with a
 * fallback to the legacy `name` string — never a fabricated translation.
 *
 * `room_types` is present only when the relation is loaded (detail
 * endpoint); `price_from` / `room_types_count` only when the list query
 * aggregated them; `gallery` only when eager-loaded (detail).
 */
class PublicHotelResource extends JsonResource
{
    /** @var array{average: float|null, count: int, categories: list<array<string, mixed>>}|null */
    private ?array $reviewSummary = null;

    /** Attach the review summary (the hotel detail endpoint only). */
    public function withReviewSummary(array $summary): static
    {
        $this->reviewSummary = $summary;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => LocalizedContent::resolve($this->name_i18n, $this->name),
            'tagline' => LocalizedContent::resolve($this->tagline_i18n),
            'description' => LocalizedContent::resolve($this->description_i18n),
            'slug' => $this->slug,
            // `city` / `country` stay the legacy English strings (`city` is
            // also the `?city=` filter key); `city_name` / `country_name` are
            // the display names in the request locale from the normalized
            // City / Country rows, falling back to the legacy string.
            'city' => $this->city,
            'country' => $this->country,
            'city_name' => $this->placeName('cityRef', $this->city),
            'country_name' => $this->placeName('countryRef', $this->country),
            'country_id' => $this->country_id,
            'city_id' => $this->city_id,
            'timezone' => $this->timezone,
            'star_rating' => $this->star_rating,
            'deposit_percentage' => $this->deposit_percentage,
            // Room Detail: "الضرائب والرسوم · شاملة" only when true.
            'prices_include_taxes' => (bool) $this->prices_include_taxes,
            // The booking service fee ("رسوم الخدمة"); null when the hotel has none.
            'service_fee' => $this->service_fee_enabled && $this->service_fee_value !== null
                ? ['type' => $this->service_fee_type, 'value' => $this->service_fee_value]
                : null,
            // Each hotel's real facility catalog membership: `key` (stable
            // machine id) + `label` (resolved to the request locale from the
            // facility's own `name_i18n`, same convention as name/tagline/
            // description above) + `icon` (the facility's own icon key, or
            // null). The facility catalog is open/admin-managed (not a fixed
            // enum) — the guest client must render whatever comes back by
            // `label`, never a hardcoded key->label map that can silently
            // drop a newly-added facility.
            // Deactivated catalog entries are hidden from guests (the hotel
            // keeps its selection, so reactivating restores them).
            'amenities' => $this->whenLoaded('facilities', fn () => $this->facilities->where('is_active', true)->map(fn ($facility) => [
                'key' => $facility->key,
                'label' => LocalizedContent::resolve($facility->name_i18n) ?? $facility->key,
                'description' => LocalizedContent::resolve($facility->description_i18n),
                'icon' => $facility->icon,
            ])->values(), []),
            'logo_url' => $this->whenLoaded('logo', fn () => $this->logo?->url()),
            'cover_url' => $this->whenLoaded('cover', fn () => $this->cover?->url()),
            'gallery' => HotelMediaResource::collection($this->whenLoaded('galleryMedia')),
            'meta_title' => LocalizedContent::resolve($this->meta_title_i18n),
            'meta_description' => LocalizedContent::resolve($this->meta_description_i18n),
            'seo_indexable' => $this->seo_indexable,
            // Every price on this resource is in the configured booking currency.
            'currency' => config('payment.currency'),
            'price_from' => $this->when(
                $this->price_from !== null,
                fn () => number_format((float) $this->price_from, 2, '.', ''),
            ),
            'room_types_count' => $this->whenNotNull($this->room_types_count ?? null),
            // Authoritative rating: the average `rating` of this hotel's
            // `published` reviews only (never pending/rejected) — see
            // Review::STATUS_PUBLISHED. Present only when the aggregate was
            // actually loaded (list/detail queries), same convention as
            // `price_from` above. Never the raw booking count used to order
            // "recommended" — that stays internal to the sort query.
            'rating' => $this->when(
                ($this->avg_rating ?? null) !== null,
                fn () => number_format((float) $this->avg_rating, 2, '.', ''),
            ),
            'reviews_count' => $this->whenNotNull($this->reviews_count ?? null),
            'room_types' => PublicRoomTypeResource::collection($this->whenLoaded('roomTypes')),
            // Hotel Detail content (Figma HOTEL_Detail_Premium). Every field
            // is null / empty when the hotel has none on file — the guest
            // app hides the row or section instead of inventing one.
            'check_in_time' => HotelResource::hhmm($this->check_in_time),
            'check_out_time' => HotelResource::hhmm($this->check_out_time),
            'reception_phone' => $this->reception_phone,
            'check_in_mode' => $this->check_in_mode,
            'suitable_for' => LocalizedContent::resolve($this->suitable_for_i18n),
            // Physical rooms on file (detail endpoint only, when counted).
            'rooms_count' => $this->whenNotNull($this->rooms_count ?? null),
            // Inactive highlights / nearby places are dashboard-only.
            'highlights' => $this->whenLoaded('highlights', fn () => $this->highlights->where('is_active', true)->map(fn ($h) => [
                'icon' => $h->icon,
                'title' => LocalizedContent::resolve($h->title_i18n),
                'subtitle' => LocalizedContent::resolve($h->subtitle_i18n),
            ])->values()),
            'location' => $this->whenLoaded('nearbyPlaces', fn () => [
                'note' => LocalizedContent::resolve($this->location_note_i18n),
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
                'nearby_places' => $this->nearbyPlaces->where('is_active', true)->map(fn ($p) => [
                    'icon' => $p->icon,
                    'category' => $p->category?->value,
                    'name' => LocalizedContent::resolve($p->name_i18n),
                    'travel_minutes' => $p->travel_minutes,
                    'distance' => $p->distance,
                    'distance_unit' => $p->distance_unit,
                    'latitude' => $p->latitude,
                    'longitude' => $p->longitude,
                ])->values(),
            ]),
            // Detail endpoint only: the published overall rating and the
            // hotel's dynamic review categories with their live averages.
            'review_summary' => $this->when($this->reviewSummary !== null, fn () => [
                'average' => $this->reviewSummary['average'],
                'count' => $this->reviewSummary['count'],
                'categories' => ReviewSummaryPresenter::categories($this->reviewSummary['categories']),
            ]),
        ];
    }

    /** A City / Country name in the request locale, or the legacy string. */
    private function placeName(string $relation, ?string $fallback): ?string
    {
        $place = $this->resource->relationLoaded($relation) ? $this->resource->getRelation($relation) : null;

        return $place === null
            ? $fallback
            : LocalizedContent::resolve(['en' => $place->name_en, 'ar' => $place->name_ar], $fallback);
    }
}
