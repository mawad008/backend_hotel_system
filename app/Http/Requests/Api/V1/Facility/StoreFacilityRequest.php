<?php

namespace App\Http\Requests\Api\V1\Facility;

use App\Domain\HotelGroup\Models\Facility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Facility::class) ?? false;
    }

    public function rules(): array
    {
        $locales = (array) config('app.available_locales', ['en']);
        $fallback = config('app.fallback_locale', 'en');

        $i18n = ['name_i18n' => ['required', 'array']];
        foreach ($locales as $locale) {
            $i18n["name_i18n.{$locale}"] = [$locale === $fallback ? 'required' : 'nullable', 'string', 'max:255'];
        }
        $i18n['description_i18n'] = ['sometimes', 'nullable', 'array'];
        foreach ($locales as $locale) {
            $i18n["description_i18n.{$locale}"] = ['nullable', 'string', 'max:255'];
        }

        return [
            // Auto-derived from name_i18n when omitted (FacilityService).
            'key' => ['sometimes', 'nullable', 'string', 'max:64', 'alpha_dash', Rule::unique('facilities', 'key')],
            ...$i18n,
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
