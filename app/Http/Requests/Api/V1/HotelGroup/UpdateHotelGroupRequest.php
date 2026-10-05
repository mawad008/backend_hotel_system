<?php

namespace App\Http\Requests\Api\V1\HotelGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHotelGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $group = $this->route('hotel_group');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            ...$this->nameI18nRules(),
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('hotel_groups', 'slug')->ignore($group)],
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
