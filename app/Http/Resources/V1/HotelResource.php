<?php

namespace App\Http\Resources\V1;

use App\Domain\HotelGroup\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/**
 * @mixin Hotel
 *
 * `country` / `city` are the legacy free-text strings, kept for backward
 * compatibility (and still what the guest Discovery API filters on). The
 * normalized relationship is exposed as `country_id` / `city_id` plus, when
 * the relations are eager-loaded, the `country_summary` / `city_summary`
 * objects.
 */
class HotelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservations_count' => $this->whenCounted('reservations'),
            'hotel_group_id' => $this->hotel_group_id,
            'name' => $this->name,
            // Raw locale maps — the dashboard form edits every language.
            'name_i18n' => $this->name_i18n,
            'tagline_i18n' => $this->tagline_i18n,
            'description_i18n' => $this->description_i18n,
            'star_rating' => $this->star_rating,
            'deposit_percentage' => $this->deposit_percentage,
            'prices_include_taxes' => (bool) $this->prices_include_taxes,
            'service_fee_enabled' => (bool) $this->service_fee_enabled,
            'service_fee_type' => $this->service_fee_type,
            'service_fee_value' => $this->service_fee_value,
            'facilities' => FacilityResource::collection($this->whenLoaded('facilities')),
            'slug' => $this->slug,
            'country_id' => $this->country_id,
            'city_id' => $this->city_id,
            'country' => $this->country,
            'city' => $this->city,
            'country_summary' => $this->summaryFor('countryRef'),
            'city_summary' => $this->summaryFor('cityRef'),
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            // Raw locale maps, same reasoning as name/tagline/description —
            // the dashboard SEO section edits every language.
            'meta_title_i18n' => $this->meta_title_i18n,
            'meta_description_i18n' => $this->meta_description_i18n,
            'seo_indexable' => $this->seo_indexable,
            // Guest Hotel Detail content — raw locale maps for the form.
            'check_in_time' => self::hhmm($this->check_in_time),
            'check_out_time' => self::hhmm($this->check_out_time),
            'reception_phone' => $this->reception_phone,
            'check_in_mode' => $this->check_in_mode,
            'suitable_for_i18n' => $this->suitable_for_i18n,
            'location_note_i18n' => $this->location_note_i18n,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'highlights' => $this->whenLoaded('highlights', fn () => $this->highlights->map(fn ($h) => [
                'id' => $h->id,
                'icon' => $h->icon,
                'title_i18n' => $h->title_i18n,
                'subtitle_i18n' => $h->subtitle_i18n,
                'is_active' => $h->is_active,
            ])->values()),
            'nearby_places' => $this->whenLoaded('nearbyPlaces', fn () => $this->nearbyPlaces->map(fn ($p) => [
                'id' => $p->id,
                'icon' => $p->icon,
                'category' => $p->category?->value,
                'name_i18n' => $p->name_i18n,
                'travel_minutes' => $p->travel_minutes,
                'distance' => $p->distance,
                'distance_unit' => $p->distance_unit,
                'latitude' => $p->latitude,
                'longitude' => $p->longitude,
                'is_active' => $p->is_active,
            ])->values()),
            'logo' => $this->when(
                $this->resource->relationLoaded('logo'),
                fn () => $this->logo ? new HotelMediaResource($this->logo) : null,
            ),
            'cover' => $this->when(
                $this->resource->relationLoaded('cover'),
                fn () => $this->cover ? new HotelMediaResource($this->cover) : null,
            ),
            'gallery' => HotelMediaResource::collection($this->whenLoaded('galleryMedia')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** A TIME column ("15:00:00") as "HH:MM", or null. */
    public static function hhmm(?string $time): ?string
    {
        return $time === null || $time === '' ? null : substr($time, 0, 5);
    }

    /**
     * @return array{id: int, name_en: string, name_ar: string}|null|MissingValue
     */
    private function summaryFor(string $relation)
    {
        return $this->when($this->resource->relationLoaded($relation), function () use ($relation) {
            $model = $this->resource->getRelation($relation);

            return $model === null ? null : [
                'id' => $model->id,
                'name_en' => $model->name_en,
                'name_ar' => $model->name_ar,
            ];
        });
    }
}
