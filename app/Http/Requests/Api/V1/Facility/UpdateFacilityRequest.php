<?php

namespace App\Http\Requests\Api\V1\Facility;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $facility = $this->route('facility');

        return $facility !== null && ($this->user()?->can('update', $facility) ?? false);
    }

    public function rules(): array
    {
        $facility = $this->route('facility');
        $locales = (array) config('app.available_locales', ['en']);
        $fallback = config('app.fallback_locale', 'en');

        $i18n = ['name_i18n' => ['sometimes', 'required', 'array']];
        foreach ($locales as $locale) {
            $i18n["name_i18n.{$locale}"] = [$locale === $fallback ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:255'];
        }
        $i18n['description_i18n'] = ['sometimes', 'nullable', 'array'];
        foreach ($locales as $locale) {
            $i18n["description_i18n.{$locale}"] = ['nullable', 'string', 'max:255'];
        }

        return [
            'key' => ['sometimes', 'string', 'max:64', 'alpha_dash', Rule::unique('facilities', 'key')->ignore($facility)],
            ...$i18n,
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Display order is unique across the catalog so the guest-facing
            // facility list has one unambiguous sequence.
            'sort_order' => ['sometimes', 'integer', 'min:0', Rule::unique('facilities', 'sort_order')->ignore($facility)],
            // is_active is intentionally not accepted here — use the
            // activate/deactivate endpoints.
        ];
    }

    public function messages(): array
    {
        return [
            'sort_order.unique' => __('validation.display_order_taken'),
        ];
    }
}
