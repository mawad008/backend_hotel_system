<?php

namespace App\Http\Requests\Api\V1\Hotel\Concerns;

use App\Domain\HotelGroup\Enums\NearbyPlaceCategory;
use App\Domain\HotelGroup\Models\HotelNearbyPlace;
use Illuminate\Validation\Rule;

/**
 * Validation for the guest Hotel Detail content edited from the dashboard
 * hotel form (check-in/out times, "suitable for", location note, map pin,
 * "why choose" highlights, nearby places). Shared by Store/UpdateHotelRequest.
 *
 * `highlights` / `nearby_places` are replace-all lists: when present, the
 * hotel's rows become exactly this list, in this order (an empty array
 * clears them); when absent they are untouched.
 */
trait HotelGuestDetailRules
{
    /** @return array<string, list<mixed>> */
    protected function guestDetailRules(): array
    {
        $locales = (array) config('app.available_locales', ['en']);

        $rules = [
            'check_in_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'check_out_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            // Dialable front-desk number: digits with an optional leading +
            // and common separators (spaces, dashes, parentheses).
            'reception_phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{5,30}$/'],
            'suitable_for_i18n' => ['sometimes', 'nullable', 'array'],
            'location_note_i18n' => ['sometimes', 'nullable', 'array'],
            // Both or neither — a pin needs a full coordinate.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            'highlights' => ['sometimes', 'array', 'max:12'],
            'highlights.*.icon' => ['nullable', 'string', 'max:64'],
            'highlights.*.title_i18n' => ['required', 'array'],
            'highlights.*.subtitle_i18n' => ['nullable', 'array'],
            'highlights.*.is_active' => ['sometimes', 'boolean'],

            'nearby_places' => ['sometimes', 'array', 'max:12'],
            'nearby_places.*.icon' => ['nullable', 'string', 'max:64'],
            'nearby_places.*.category' => ['nullable', Rule::enum(NearbyPlaceCategory::class)],
            'nearby_places.*.name_i18n' => ['required', 'array'],
            'nearby_places.*.travel_minutes' => ['nullable', 'integer', 'between:1,1440'],
            // A distance needs its unit (and vice versa); a place pin needs both coordinates.
            'nearby_places.*.distance' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'required_with:nearby_places.*.distance_unit'],
            'nearby_places.*.distance_unit' => ['nullable', Rule::in(HotelNearbyPlace::DISTANCE_UNITS), 'required_with:nearby_places.*.distance'],
            'nearby_places.*.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:nearby_places.*.longitude'],
            'nearby_places.*.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:nearby_places.*.latitude'],
            'nearby_places.*.is_active' => ['sometimes', 'boolean'],
        ];

        foreach ($locales as $locale) {
            $rules["suitable_for_i18n.{$locale}"] = ['nullable', 'string', 'max:120'];
            $rules["location_note_i18n.{$locale}"] = ['nullable', 'string', 'max:160'];
            $rules["highlights.*.title_i18n.{$locale}"] = ['nullable', 'string', 'max:60'];
            $rules["highlights.*.subtitle_i18n.{$locale}"] = ['nullable', 'string', 'max:120'];
            $rules["nearby_places.*.name_i18n.{$locale}"] = ['nullable', 'string', 'max:80'];
        }

        return $rules;
    }
}
