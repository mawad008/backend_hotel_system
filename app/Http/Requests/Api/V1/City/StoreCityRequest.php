<?php

namespace App\Http\Requests\Api\V1\City;

use App\Domain\Location\Models\City;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', City::class) ?? false;
    }

    public function rules(): array
    {
        $countryId = $this->input('country_id');

        return [
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name_en' => [
                'required', 'string', 'max:255',
                Rule::unique('cities', 'name_en')->where('country_id', $countryId),
            ],
            'name_ar' => [
                'required', 'string', 'max:255',
                Rule::unique('cities', 'name_ar')->where('country_id', $countryId),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
