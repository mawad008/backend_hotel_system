<?php

namespace App\Http\Requests\Api\V1\Country;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $country = $this->route('country');

        return $country !== null && ($this->user()?->can('update', $country) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $country = $this->route('country');

        return [
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:8', 'alpha_num', Rule::unique('countries', 'code')->ignore($country)],
            // is_active is intentionally not accepted here — use the
            // activate/deactivate endpoints.
        ];
    }
}
