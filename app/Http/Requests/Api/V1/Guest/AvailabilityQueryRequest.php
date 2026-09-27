<?php

namespace App\Http\Requests\Api\V1\Guest;

use Illuminate\Foundation\Http\FormRequest;

class AvailabilityQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
        ];
    }

    public function adults(): int
    {
        return (int) ($this->validated('adults') ?? 1);
    }

    public function children(): int
    {
        return (int) ($this->validated('children') ?? 0);
    }
}
