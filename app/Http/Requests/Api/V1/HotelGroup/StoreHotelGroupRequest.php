<?php

namespace App\Http\Requests\Api\V1\HotelGroup;

use Illuminate\Foundation\Http\FormRequest;

class StoreHotelGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `name` may be omitted when name_i18n is sent — HotelGroupService
            // derives it from the fallback-locale entry.
            'name' => ['required_without:name_i18n', 'string', 'max:255'],
            ...$this->nameI18nRules(),
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:hotel_groups,slug'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Both language names are required whenever `name_i18n` is sent, so a
     * group always reads correctly in either dashboard language.
     *
     * @return array<string, array<int, mixed>>
     */
    private function nameI18nRules(): array
    {
        $rules = ['name_i18n' => ['sometimes', 'array']];
        foreach ((array) config('app.available_locales', ['en']) as $locale) {
            $rules["name_i18n.{$locale}"] = ['required_with:name_i18n', 'string', 'max:255'];
        }

        return $rules;
    }
}
