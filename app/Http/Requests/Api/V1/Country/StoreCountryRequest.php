<?php

namespace App\Http\Requests\Api\V1\Country;

use App\Domain\Location\Models\Country;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Country::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:8', 'alpha_num', Rule::unique('countries', 'code')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
