<?php

namespace App\Http\Requests\Api\V1\Guest;

use App\Domain\GuestAccess\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize((string) $this->input('phone'))]);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:'.PhoneNumber::PATTERN],
        ];
    }

    public function phone(): string
    {
        return (string) $this->validated('phone');
    }
}
