<?php

namespace App\Http\Requests\Api\V1\City;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $city = $this->route('city');

        return $city !== null && ($this->user()?->can('update', $city) ?? false);
    }

    public function rules(): array
    {
        $city = $this->route('city');
        // Uniqueness is checked against the target country (the one being
        // set, or the city's current one if country_id is unchanged).
        $countryId = $this->input('country_id', $city?->country_id);

        return [
            'country_id' => ['sometimes', 'required', 'integer', 'exists:countries,id'],
            'name_en' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('cities', 'name_en')->where('country_id', $countryId)->ignore($city),
            ],
            'name_ar' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('cities', 'name_ar')->where('country_id', $countryId)->ignore($city),
            ],
        ];
    }
}
