<?php

namespace App\Http\Requests\Api\V1\Hotel;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Location\Models\City;
use App\Http\Requests\Api\V1\Hotel\Concerns\HotelGuestDetailRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHotelRequest extends FormRequest
{
    use HotelGuestDetailRules;

    public function authorize(): bool
    {
        $hotel = $this->route('hotel');

        return $hotel !== null && ($this->user()?->can('update', $hotel) ?? false);
    }

    public function rules(): array
    {
        $hotel = $this->route('hotel');

        $locales = (array) config('app.available_locales', ['en']);

        $i18n = [];
        foreach (['name_i18n', 'tagline_i18n', 'description_i18n'] as $field) {
            $i18n[$field] = ['sometimes', 'nullable', 'array'];
            foreach ($locales as $locale) {
                $i18n["{$field}.{$locale}"] = ['nullable', 'string', 'max:2000'];
            }
        }

        // SEO metadata — bilingual, same `*_i18n` pattern, with the
        // conventional search-engine snippet length limits.
        $seo = [
            'meta_title_i18n' => ['sometimes', 'nullable', 'array'],
            'meta_description_i18n' => ['sometimes', 'nullable', 'array'],
        ];
        foreach ($locales as $locale) {
            $seo["meta_title_i18n.{$locale}"] = ['nullable', 'string', 'max:60'];
            $seo["meta_description_i18n.{$locale}"] = ['nullable', 'string', 'max:160'];
        }

        return [
            'hotel_group_id' => ['sometimes', 'integer', 'exists:hotel_groups,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            ...$i18n,
            'star_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            // Approved: optional on (partial) update; when sent it must be
            // 0–100 and can never be cleared to null.
            'deposit_percentage' => ['sometimes', 'required', 'numeric', 'between:0,100', 'decimal:0,2'],
            // Whether displayed rates already include taxes / the service fee.
            'prices_include_taxes' => ['sometimes', 'boolean'],
            // Booking service fee ("رسوم الخدمة"): fixed per booking or % of the stay.
            'service_fee_enabled' => ['sometimes', 'boolean'],
            'service_fee_type' => ['nullable', 'required_if_accepted:service_fee_enabled', Rule::in(Hotel::SERVICE_FEE_TYPES)],
            'service_fee_value' => ['nullable', 'required_if_accepted:service_fee_enabled', 'numeric', 'min:0', 'max:100000', 'decimal:0,2',
                Rule::when($this->input('service_fee_type') === Hotel::SERVICE_FEE_PERCENTAGE, ['max:100'])],
            'check_in_mode' => ['sometimes', 'string', Rule::in(Hotel::CHECK_IN_MODES)],
            // Selected from the Facility catalog — replaces the old
            // free-text `amenities` array.
            'facility_ids' => ['sometimes', 'nullable', 'array'],
            'facility_ids.*' => ['integer', 'exists:facilities,id'],
            ...$seo,
            'seo_indexable' => ['sometimes', 'boolean'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('hotels', 'slug')->ignore($hotel)],
            'country_id' => ['sometimes', 'required', 'integer', 'exists:countries,id'],
            'city_id' => ['sometimes', 'required', 'integer', 'exists:cities,id'],
            'country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            ...$this->guestDetailRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hotel = $this->route('hotel');

            // Resolve the effective country/city after this update: a value
            // sent in the request wins, otherwise the hotel's current one.
            $countryId = $this->input('country_id', $hotel?->country_id);
            $cityId = $this->input('city_id', $hotel?->city_id);

            if (! $countryId || ! $cityId) {
                return;
            }

            if (! $this->has('country_id') && ! $this->has('city_id')) {
                return; // location untouched
            }

            $belongs = City::query()->whereKey($cityId)->where('country_id', $countryId)->exists();

            if (! $belongs) {
                $validator->errors()->add('city_id', __('api.location.city_country_mismatch'));
            }
        });
    }
}
