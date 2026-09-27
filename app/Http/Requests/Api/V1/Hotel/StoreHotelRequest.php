<?php

namespace App\Http\Requests\Api\V1\Hotel;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Location\Models\City;
use App\Http\Requests\Api\V1\Hotel\Concerns\HotelGuestDetailRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHotelRequest extends FormRequest
{
    use HotelGuestDetailRules;

    /**
     * Authorize before validation so an unauthorized caller gets a clean
     * 403 and never sees which fields are required.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Hotel::class) ?? false;
    }

    public function rules(): array
    {
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
            'hotel_group_id' => ['required', 'integer', 'exists:hotel_groups,id'],
            // `name` stays the authoritative search/slug string; it may be
            // omitted only when name_i18n carries a fallback-locale value
            // (HotelService::syncLegacyName derives it).
            'name' => ['required_without:name_i18n', 'string', 'max:255'],
            ...$i18n,
            'star_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            // Pre-booking deposit as a % of the booked room price (100 SAR at
            // 10% holds 10 SAR). Every hotel sets its own.
            // Approved: required on create, 0–100 (0 = no deposit hold).
            'deposit_percentage' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
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
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:hotels,slug'],
            // Normalized location. country_id is required; city_id is
            // required and must belong to that country (checked below).
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            // Legacy free-text — still accepted for backward compatibility
            // but overwritten from the referenced City/Country by the service.
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
            $countryId = $this->input('country_id');
            $cityId = $this->input('city_id');

            if (! $countryId || ! $cityId) {
                return;
            }

            $belongs = City::query()->whereKey($cityId)->where('country_id', $countryId)->exists();

            if (! $belongs) {
                $validator->errors()->add('city_id', __('api.location.city_country_mismatch'));
            }
        });
    }
}
